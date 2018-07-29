<?php

namespace Amp\Dns\Internal;

use Amp\Dns\ResolutionException;
use LibDNS\Decoder\Decoder;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\Encoder;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Messages\Message;

/** @internal */
class UdpSocket extends Socket
{
    /** @var Encoder */
    private $encoder;

    /** @var Decoder */
    private $decoder;

    public static function connect(string $uri): Socket
    {
        if (!$socket = @\stream_socket_client($uri, $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT)) {
            throw new ResolutionException(\sprintf(
                "Connection to %s failed: [Error #%d] %s",
                $uri,
                $errno,
                $errstr
            ));
        }

        return new self($socket);
    }

    protected function __construct($socket)
    {
        parent::__construct($socket);

        $this->encoder = (new EncoderFactory)->create();
        $this->decoder = (new DecoderFactory)->create();
    }

    protected function send(Message $message): void
    {
        $this->write($this->encoder->encode($message));
    }

    protected function receive(): Message
    {
        $data = $this->read();

        if ($data === null) {
            throw new ResolutionException("Reading from the server failed");
        }

        return $this->decoder->decode($data);
    }

    public function isAlive(): bool
    {
        return true;
    }
}
