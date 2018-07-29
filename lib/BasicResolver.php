<?php

namespace Amp\Dns;

use Amp\Cache\ArrayCache;
use Amp\Cache\Cache;
use Amp\Dns\Internal\Socket;
use Amp\Dns\Internal\TcpSocket;
use Amp\Dns\Internal\UdpSocket;
use Amp\Loop;
use Amp\MultiReasonException;
use Amp\Uri\InvalidDnsNameException;
use Concurrent\Awaitable;
use Concurrent\Deferred;
use Concurrent\Task;
use LibDNS\Messages\Message;
use LibDNS\Records\Question;
use LibDNS\Records\QuestionFactory;
use LibDNS\Records\Resource;
use function Amp\some;
use function Amp\Uri\normalizeDnsName;

final class BasicResolver implements Resolver
{
    private const CACHE_PREFIX = "amphp.dns.";

    /** @var ConfigLoader */
    private $configLoader;

    /** @var QuestionFactory */
    private $questionFactory;

    /** @var Config|null */
    private $config;

    /** @var Awaitable|null */
    private $pendingConfig;

    /** @var Cache */
    private $cache;

    /** @var Socket[] */
    private $sockets = [];

    /** @var Awaitable[] */
    private $pendingSockets = [];

    /** @var Awaitable[] */
    private $pendingQueries = [];

    /** @var string */
    private $gcWatcher;

    public function __construct(Cache $cache = null, ConfigLoader $configLoader = null)
    {
        $this->cache = $cache ?? new ArrayCache(5000 /* default gc interval */, 256 /* size */);
        $this->configLoader = $configLoader ?? (\stripos(PHP_OS, "win") === 0
                ? new WindowsConfigLoader
                : new UnixConfigLoader);

        $this->questionFactory = new QuestionFactory;

        $sockets = &$this->sockets;
        $this->gcWatcher = Loop::repeat(5000, static function () use (&$sockets) {
            if (!$sockets) {
                return;
            }

            $now = \time();

            foreach ($sockets as $key => $server) {
                if ($server->getLastActivity() < $now - 60) {
                    $server->close();
                    unset($sockets[$key]);
                }
            }
        });

        Loop::unreference($this->gcWatcher);
    }

    public function __destruct()
    {
        Loop::cancel($this->gcWatcher);
    }

    /** @inheritdoc */
    public function resolve(string $name, int $typeRestriction = null): array
    {
        if ($typeRestriction !== null && $typeRestriction !== Record::A && $typeRestriction !== Record::AAAA) {
            throw new \Error("Invalid value for parameter 2: null|Record::A|Record::AAAA expected");
        }

        if (!$this->config) {
            $this->reloadConfig();
        }

        switch ($typeRestriction) {
            case Record::A:
                if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return [new Record($name, Record::A, null)];
                }

                if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    throw new ResolutionException("Got an IPv6 address, but type is restricted to IPv4");
                }

                break;
            case Record::AAAA:
                if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    return [new Record($name, Record::AAAA, null)];
                }

                if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    throw new ResolutionException("Got an IPv4 address, but type is restricted to IPv6");
                }

                break;
            default:
                if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return [new Record($name, Record::A, null)];
                }

                if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    return [new Record($name, Record::AAAA, null)];
                }

                break;
        }

        try {
            $name = normalizeDnsName($name);
        } catch (InvalidDnsNameException $e) {
            throw new ResolutionException("Invalid DNS name: {$name}", 0, $e);
        }

        if ($records = $this->queryHosts($name, $typeRestriction)) {
            return $records;
        }

        for ($redirects = 0; $redirects < 5; $redirects++) {
            try {
                if ($typeRestriction) {
                    $records = $this->query($name, $typeRestriction);
                } else {
                    try {
                        [, $records] = Task::await(some([
                            Task::async([$this, 'query'], $name, Record::A),
                            Task::async([$this, 'query'], $name, Record::AAAA),
                        ]));

                        $records = \array_merge(...$records);

                        break; // Break redirect loop, otherwise we query the same records 5 times
                    } catch (MultiReasonException $e) {
                        $errors = [];

                        foreach ($e->getReasons() as $reason) {
                            if ($reason instanceof NoRecordException) {
                                throw $reason;
                            }

                            $errors[] = $reason->getMessage();
                        }

                        throw new ResolutionException("All query attempts failed for {$name}: " . \implode(", ", $errors), 0, $e);
                    }
                }
            } catch (NoRecordException $e) {
                try {
                    /** @var Record[] $cnameRecords */
                    $cnameRecords = $this->query($name, Record::CNAME);
                    $name = $cnameRecords[0]->getValue();
                    continue;
                } catch (NoRecordException $e) {
                    /** @var Record[] $dnameRecords */
                    $dnameRecords = $this->query($name, Record::DNAME);
                    $name = $dnameRecords[0]->getValue();
                    continue;
                }
            }
        }

        return $records;
    }

    private function queryHosts(string $name, int $typeRestriction = null): array
    {
        $hosts = $this->config->getKnownHosts();
        $records = [];

        $returnIPv4 = $typeRestriction === null || $typeRestriction === Record::A;
        $returnIPv6 = $typeRestriction === null || $typeRestriction === Record::AAAA;

        if ($returnIPv4 && isset($hosts[Record::A][$name])) {
            $records[] = new Record($hosts[Record::A][$name], Record::A, null);
        }

        if ($returnIPv6 && isset($hosts[Record::AAAA][$name])) {
            $records[] = new Record($hosts[Record::AAAA][$name], Record::AAAA, null);
        }

        return $records;
    }

    /** @inheritdoc */
    public function query(string $name, int $type): array
    {
        $pendingQueryKey = $type . " " . $name;

        if (isset($this->pendingQueries[$pendingQueryKey])) {
            return Task::await($this->pendingQueries[$pendingQueryKey]);
        }

        $awaitable = Task::async(function () use ($name, $type) {
            if (!$this->config) {
                $this->reloadConfig();
            }

            $name = $this->normalizeName($name, $type);
            $question = $this->createQuestion($name, $type);

            if (null !== $cachedValue = $this->cache->get($this->getCacheKey($name, $type))) {
                return $this->decodeCachedResult($name, $type, $cachedValue);
            }

            $nameservers = $this->config->getNameservers();
            $attempts = $this->config->getAttempts();
            $protocol = "udp";
            $attempt = 0;

            /** @var Socket $socket */
            $uri = $protocol . "://" . $nameservers[0];
            $socket = $this->getSocket($uri);

            $attemptDescription = [];

            while ($attempt < $attempts) {
                try {
                    if (!$socket->isAlive()) {
                        unset($this->sockets[$uri]);
                        $socket->close();

                        /** @var Socket $server */
                        $i = $attempt % \count($nameservers);
                        $uri = $protocol . "://" . $nameservers[$i];
                        $socket = $this->getSocket($uri);
                    }

                    $attemptDescription[] = $uri;

                    /** @var Message $response */
                    $response = $socket->ask($question, $this->config->getTimeout());
                    $this->assertAcceptableResponse($response);

                    // UDP sockets are never reused, they're not in the $this->sockets map
                    if ($protocol === "udp") {
                        // Defer call, because it interferes with the unreference() call in Internal\Socket otherwise
                        Loop::defer(function () use ($socket) {
                            $socket->close();
                        });
                    }

                    if ($response->isTruncated()) {
                        if ($protocol !== "tcp") {
                            // Retry with TCP, don't count attempt
                            $protocol = "tcp";
                            $i = $attempt % \count($nameservers);
                            $uri = $protocol . "://" . $nameservers[$i];
                            $socket = $this->getSocket($uri);
                            continue;
                        }

                        throw new ResolutionException("Server returned a truncated response for '{$name}' (" . Record::getName($type) . ")");
                    }

                    $answers = $response->getAnswerRecords();
                    $result = [];
                    $ttls = [];

                    /** @var Resource $record */
                    foreach ($answers as $record) {
                        $recordType = $record->getType();
                        $result[$recordType][] = (string) $record->getData();

                        // Cache for max one day
                        $ttls[$recordType] = \min($ttls[$recordType] ?? 86400, $record->getTTL());
                    }

                    foreach ($result as $recordType => $records) {
                        $this->cache->set($this->getCacheKey($name, $recordType), \json_encode($records), $ttls[$recordType]);
                    }

                    if (!isset($result[$type])) {
                        // "it MUST NOT cache it for longer than five (5) minutes" per RFC 2308 section 7.1
                        $this->cache->set($this->getCacheKey($name, $type), \json_encode([]), 300);
                        throw new NoRecordException("No records returned for '{$name}' (" . Record::getName($type) . ")");
                    }

                    return \array_map(function ($data) use ($type, $ttls) {
                        return new Record($data, $type, $ttls[$type]);
                    }, $result[$type]);
                } catch (TimeoutException $e) {
                    // Defer call, because it might interfere with the unreference() call in Internal\Socket otherwise
                    Loop::defer(function () use ($socket, $uri) {
                        unset($this->sockets[$uri]);
                        $socket->close();
                    });

                    $i = ++$attempt % \count($nameservers);
                    $uri = $protocol . "://" . $nameservers[$i];
                    $socket = $this->getSocket($uri);

                    continue;
                }
            }

            throw new TimeoutException(\sprintf(
                "No response for '%s' (%s) from any nameserver after %d attempts, tried %s",
                $name,
                Record::getName($type),
                $attempts,
                \implode(", ", $attemptDescription)
            ));
        });

        $this->pendingQueries[$type . " " . $name] = $awaitable;
        Deferred::transform($awaitable, function () use ($name, $type) {
            unset($this->pendingQueries[$type . " " . $name]);
        });

        // FIXME: It makes a difference whether we await $this->pendingQueries[$type . " " . $name] or $awaitable here
        // @see testRequestSharing
        return Task::await($this->pendingQueries[$type . " " . $name]);
    }

    /**
     * Reloads the configuration in the background.
     *
     * Once it's finished, the configuration will be used for new requests.
     */
    public function reloadConfig(): void
    {
        if ($this->pendingConfig) {
            Task::await($this->pendingConfig);
            return;
        }

        $awaitable = Task::async(function () {
            try {
                $this->config = $this->configLoader->loadConfig();
            } finally {
                $this->pendingConfig = null;
            }
        });

        $this->pendingConfig = $awaitable;

        Task::await($awaitable);
    }

    /**
     * @param string $name
     * @param int    $type
     *
     * @return Question
     */
    private function createQuestion(string $name, int $type): Question
    {
        if (0 > $type || 0xffff < $type) {
            $message = \sprintf('%d does not correspond to a valid record type (must be between 0 and 65535).', $type);
            throw new \Error($message);
        }

        $question = $this->questionFactory->create($type);
        $question->setName($name);

        return $question;
    }

    private function getCacheKey(string $name, int $type): string
    {
        return self::CACHE_PREFIX . $name . "#" . $type;
    }

    /** @throws ResolutionException */
    private function decodeCachedResult(string $name, string $type, string $encoded): array
    {
        $decoded = \json_decode($encoded, true);

        if ($decoded === []) {
            throw new NoRecordException("No records returned for {$name} (cached result)");
        }

        if (!$decoded) {
            throw new ResolutionException("Corrupt cache data returned for {$name}");
        }

        $result = [];

        foreach ($decoded as $data) {
            $result[] = new Record($data, $type);
        }

        return $result;
    }

    private function normalizeName(string $name, int $type): string
    {
        if ($type === Record::PTR) {
            if (($packedIp = @inet_pton($name)) !== false) {
                if (isset($packedIp[4])) { // IPv6
                    $name = \wordwrap(\strrev(\bin2hex($packedIp)), 1, ".", true) . ".ip6.arpa";
                } else { // IPv4
                    $name = \inet_ntop(\strrev($packedIp)) . ".in-addr.arpa";
                }
            }
        } elseif (\in_array($type, [Record::A, Record::AAAA], true)) {
            $name = normalizeDnsName($name);
        }

        return $name;
    }

    /** @throws ResolutionException */
    private function getSocket(string $uri): Socket
    {
        // We use a new socket for each UDP request, as that increases the entropy and mitigates response forgery.
        if (\substr($uri, 0, 3) === "udp") {
            return UdpSocket::connect($uri);
        }

        // Over TCP we might reuse sockets if the server allows to keep them open. Sequence IDs in TCP are already
        // better than a random port. Additionally, a TCP connection is more expensive.
        if (isset($this->sockets[$uri])) {
            return $this->sockets[$uri];
        }

        if (isset($this->pendingSockets[$uri])) {
            return Task::await($this->pendingSockets[$uri]);
        }

        $pendingSocket = Task::async(function () use ($uri) {
            try {
                $socket = TcpSocket::connect($uri);
                $this->sockets[$uri] = $socket;

                return $socket;
            } finally {
                unset($this->pendingSockets[$uri]);
            }
        });

        $this->pendingSockets[$uri] = $pendingSocket;

        return Task::await($pendingSocket);
    }

    private function assertAcceptableResponse(Message $response): void
    {
        if ($response->getResponseCode() !== 0) {
            throw new ResolutionException(\sprintf("Server returned error code: %d", $response->getResponseCode()));
        }
    }
}
