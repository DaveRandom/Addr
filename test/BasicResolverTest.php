<?php

namespace Amp\Dns\Test;

use Amp\Dns\Rfc1035Resolver;
use Amp\Dns\Record;
use Amp\Dns\DnsException;
use Amp\PHPUnit\TestCase;

class BasicResolverTest extends TestCase
{
    public function testResolveSecondParameterAcceptedValues(): void
    {
        $this->expectException(\Error::class);
        (new Rfc1035Resolver)->resolve("abc.de", Record::TXT);
    }

    public function testIpAsArgumentWithIPv4Restriction(): void
    {
        $this->expectException(DnsException::class);
        (new Rfc1035Resolver)->resolve("::1", Record::A);
    }

    public function testIpAsArgumentWithIPv6Restriction(): void
    {
        $this->expectException(DnsException::class);
        (new Rfc1035Resolver)->resolve("127.0.0.1", Record::AAAA);
    }
}
