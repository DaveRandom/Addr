<?php

namespace Amp\Dns\Internal;

use Amp;
use Amp\Dns\ResolutionException;
use Amp\Dns\TimeoutException;
use Amp\Loop;
use Amp\Parser\Parser;
use Concurrent\Deferred;
use Concurrent\Task;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\Encoder;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Messages\Message;
use function Amp\timeout;

/** @internal */
class TcpSocket extends Socket
{
    /** @var Encoder */
    private $encoder;

    /** @var \SplQueue */
    private $queue;

    /** @var Parser */
    private $parser;

    /** @var bool */
    private $isAlive = true;

    public static function connect(string $uri, int $timeout = 5000): Socket
    {
        if (!$socket = @\stream_socket_client($uri, $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT)) {
            throw new ResolutionException(\sprintf(
                "Connection to %s failed: [Error #%d] %s",
                $uri,
                $errno,
                $errstr
            ));
        }

        \stream_set_blocking($socket, false);

        $deferred = new Deferred;

        $watcher = Loop::onWritable($socket, static function () use ($socket, $deferred) {
            $deferred->resolve(new self($socket));
        });

        try {
            return Task::await(timeout($deferred->awaitable(), $timeout));
        } catch (Amp\TimeoutException $e) {
            throw new TimeoutException("Name resolution timed out, could not connect to server at $uri");
        } finally {
            Loop::cancel($watcher);
        }
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

    protected function __construct($socket)
    {
        parent::__construct($socket);

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
                throw new ResolutionException("Reading from the server failed");
            }

            $this->parser->push($chunk);
        }

        return $this->queue->shift();
    }

    public function isAlive(): bool
    {
        return $this->isAlive;
    }
}
