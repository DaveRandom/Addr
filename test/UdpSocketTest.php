<?php

namespace Amp\Dns\Test;

use Amp\Dns;

class UdpSocketTest extends SocketTest
{
    protected function connect(): Dns\Internal\Transport
    {
        return Dns\Internal\UdpTransport::connect("udp://8.8.8.8:53");
    }

    public function testInvalidUri(): void
    {
        $this->expectException(Dns\DnsException::class);
        Dns\Internal\UdpTransport::connect("udp://8.8.8.8");
    }
}
