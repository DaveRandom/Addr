<?php

namespace Amp\Dns\Test;

use Amp\Dns\Config;
use Amp\Dns\ConfigException;
use Amp\Dns\UnixConfigLoader;
use Amp\PHPUnit\TestCase;

class UnixConfigLoaderTest extends TestCase {
    public function test(): void
    {
        $loader = new UnixConfigLoader(__DIR__ . "/data/resolv.conf");

        /** @var Config $result */
        $result = $loader->loadConfig();

        $this->assertSame([
            "127.0.0.1:53",
            "[2001:4860:4860::8888]:53",
        ], $result->getNameservers());

        $this->assertSame(5000, $result->getTimeout());
        $this->assertSame(3, $result->getAttempts());
    }

    public function testNoDefaultsOnConfNotFound(): void
    {
        $this->expectException(ConfigException::class);
        (new UnixConfigLoader(__DIR__ . "/data/non-existent.conf"))->loadConfig();
    }
}
