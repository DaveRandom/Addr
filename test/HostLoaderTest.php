<?php

namespace Amp\Dns\Test;

use Amp\Dns\HostLoader;
use Amp\Dns\Record;
use Amp\PHPUnit\TestCase;

class HostLoaderTest extends TestCase
{
    public function testIgnoresCommentsAndParsesBasicEntry(): void
    {
        $loader = new HostLoader(__DIR__ . "/data/hosts");
        $this->assertSame([
            Record::A => [
                "localhost" => "127.0.0.1",
            ],
        ], $loader->loadHosts());
    }

    public function testReturnsEmptyErrorOnFileNotFound(): void
    {
        $loader = new HostLoader(__DIR__ . "/data/hosts.not.found");
        $this->assertSame([], $loader->loadHosts());
    }

    public function testIgnoresInvalidNames(): void
    {
        $loader = new HostLoader(__DIR__ . "/data/hosts.invalid.name");
        $this->assertSame([
            Record::A => [
                "localhost" => "127.0.0.1",
            ],
        ], $loader->loadHosts());
    }
}
