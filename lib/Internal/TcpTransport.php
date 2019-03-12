<?php

namespace Amp\Dns\Internal;

use Amp;
use Amp\Dns\DnsException;
use Amp\Parser\Parser;
use Concurrent\Network\TcpSocket;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\Encoder;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Messages\Message;
use function League\Uri\parse;

/** @internal */
class TcpTransport extends Transport
{
    /** @var Encoder */
    private $encoder;

    /** @var \SplQueue */
    private $queue;

    /** @var Parser */
    private $parser;

    /** @var bool */
    private $isAlive = true;

    /** @var TcpSocket */
    private $socket;

    public static function createFromUri(string $uri, int $timeout = 5000): Transport
    {
        $parsedUri = parse($uri);
        if ($parsedUri['scheme'] !== 'tcp') {
            throw new DnsException(self::class . " does not support the '{$parsedUri['scheme']}' scheme");
        }

        return new self($parsedUri['host'], $parsedUri['port'], $timeout);
    }

    public static function parser(callable $callback): \Generator
    {
        $decoder = (new DecoderFactory)->create();

        while (true) {
            $length = yield 2;
            $length = \unpack("n", $length)[1];

            $rawData = yield $length;
            $callback($decoder->decode($rawData));
        }
    }

    protected function __construct(string $remoteAddress, int $remotePort, int $timeout)
    {
        parent::__construct();

        $this->socket = TcpSocket::connect($remoteAddress, $remotePort);
        $this->encoder = (new EncoderFactory)->create();
        $this->queue = new \SplQueue;
        $this->parser = new Parser(self::parser([$this->queue, 'push']));
    }

    protected function send(Message $message): void
    {
        $data = $this->encoder->encode($message);

        try {
            $this->write(\pack("n", \strlen($data)) . $data);
        } catch (\Throwable $exception) {
            $this->isAlive = false;

            throw $exception;
        }
    }

    protected function receive(): Message
    {
        while ($this->queue->isEmpty()) {
            $chunk = $this->read();

            if ($chunk === null) {
                $this->isAlive = false;
                throw new DnsException("Reading from the server failed");
            }

            $this->parser->push($chunk);
        }

        return $this->queue->shift();
    }

    public function isAlive(): bool
    {
        return $this->isAlive;
    }

    public function close(): void
    {
        // TODO: Implement close() method.
    }

    /**
     * @throws DnsException
     */
    protected function read(): ?string
    {
        // TODO: Implement read() method.
    }

    /**
     * @param string $data
     *
     * @throws DnsException
     */
    protected function write(string $data): void
    {
        // TODO: Implement write() method.
    }
}
