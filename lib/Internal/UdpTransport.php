<?php

namespace Amp\Dns\Internal;

use Amp\Dns\DnsException;
use Concurrent\Network\UdpDatagram;
use Concurrent\Network\UdpSocket;
use LibDNS\Decoder\Decoder;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\Encoder;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Messages\Message;
use function League\Uri\parse;

/** @internal */
class UdpTransport extends Transport
{
    /** @var Encoder */
    private $encoder;

    /** @var Decoder */
    private $decoder;

    /** @var string */
    private $remoteAddress;

    /** @var int */
    private $remotePort;

    /** @var UdpSocket */
    private $socket;

    public static function createFromUri(string $uri): Transport
    {
        $parsedUri = parse($uri);
        if ($parsedUri['scheme'] !== 'udp') {
            throw new DnsException(self::class . " does not support the '{$parsedUri['scheme']}' scheme");
        }

        return new self($parsedUri['host'], $parsedUri['port']);
    }

    protected function __construct(string $remoteAddress, int $remotePort)
    {
        parent::__construct();

        $this->remoteAddress = $remoteAddress;
        $this->remotePort = $remotePort;
        $this->socket = UdpSocket::bind('0.0.0.0', 0);
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
            throw new DnsException("Reading from the server failed");
        }

        return $this->decoder->decode($data);
    }

    public function isAlive(): bool
    {
        return true;
    }

    public function close(): void
    {
        $this->socket->close();
    }

    /**
     * @throws DnsException
     */
    protected function read(): ?string
    {
        do {
            try {
                $datagram = $this->socket->receive();
            } catch (\Exception $e) {
                throw new DnsException("Failed to receive packet from '{$this->remoteAddress}' on port {$this->remotePort}");
            }
        } while ($datagram->address !== $this->remoteAddress || $datagram->port !== $this->remotePort);

        return $datagram->data;
    }

    /**
     * @param string $data
     *
     * @throws DnsException
     */
    protected function write(string $data): void
    {
        try {
            $datagram = new UdpDatagram($data, $this->remoteAddress, $this->remotePort);
            $this->socket->send($datagram);
        } catch (\Exception $e) {
            throw new DnsException("Failed to send packet to '{$this->remoteAddress}' on port {$this->remotePort}");
        }
    }
}
