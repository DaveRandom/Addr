<?php

namespace Amp\Dns\Test;

use Amp\Dns\BasicResolver;
use Amp\Dns\Record;
use Amp\Dns\ResolutionException;
use Amp\PHPUnit\TestCase;

class BasicResolverTest extends TestCase
{
    public function testResolveSecondParameterAcceptedValues(): void
    {
        $this->expectException(\Error::class);
        (new BasicResolver)->resolve("abc.de", Record::TXT);
    }

    public function testIpAsArgumentWithIPv4Restriction(): void
    {
        $this->expectException(ResolutionException::class);
        (new BasicResolver)->resolve("::1", Record::A);
    }

    public function testIpAsArgumentWithIPv6Restriction(): void
    {
        $this->expectException(ResolutionException::class);
        (new BasicResolver)->resolve("127.0.0.1", Record::AAAA);
    }
}
