<?php

namespace Amp\Dns\Test;

use Amp\Dns;
use Amp\Dns\Record;
use Amp\PHPUnit\TestCase;
use Concurrent\Task;

class IntegrationTest extends TestCase
{
    /**
     * @param string $hostname
     *
     * @group internet
     * @dataProvider provideHostnames
     */
    public function testResolve($hostname): void
    {
        $result = (new Dns\Rfc1035Resolver)->resolve($hostname);

        /** @var Record $record */
        $record = $result[0];
        $inAddr = @\inet_pton($record->getValue());
        $this->assertNotFalse(
            $inAddr,
            "Server name $hostname did not resolve to a valid IP address"
        );
    }

    /**
     * @group internet
     */
    public function testWorksAfterConfigReload(): void
    {
        $resolver = new Dns\Rfc1035Resolver;
        $resolver->query("google.com", Record::A);
        $resolver->reloadConfig();
        $this->assertNotEmpty($resolver->query("example.com", Record::A));
    }

    public function testResolveIPv4only(): void
    {
        $records = (new Dns\Rfc1035Resolver)->resolve("google.com", Record::A);

        /** @var Record $record */
        foreach ($records as $record) {
            $this->assertSame(Record::A, $record->getType());
            $inAddr = @\inet_pton($record->getValue());
            $this->assertNotFalse(
                $inAddr,
                "Server name google.com did not resolve to a valid IP address"
            );
        }
    }

    public function testResolveIPv6only(): void
    {
        $records = (new Dns\Rfc1035Resolver)->resolve("google.com", Record::AAAA);

        /** @var Record $record */
        foreach ($records as $record) {
            $this->assertSame(Record::AAAA, $record->getType());
            $inAddr = @\inet_pton($record->getValue());
            $this->assertNotFalse(
                $inAddr,
                "Server name google.com did not resolve to a valid IP address"
            );
        }
    }

    public function testPtrLookup(): void
    {
        $result = (new Dns\Rfc1035Resolver)->query("8.8.4.4", Record::PTR);

        /** @var Record $record */
        $record = $result[0];
        $this->assertSame("google-public-dns-b.google.com", $record->getValue());
        $this->assertNotNull($record->getTtl());
        $this->assertSame(Record::PTR, $record->getType());
    }

    /**
     * Test that two concurrent requests to the same resource share the same request and do not result in two requests
     * being sent.
     */
    public function testRequestSharing(): void
    {
        $resolver = new Dns\Rfc1035Resolver;

        $promise1 = Task::async([$resolver, 'query'], "example.com", Record::A);
        $promise2 = Task::async([$resolver, 'query'], "example.com", Record::A);

        $this->assertSame(Task::await($promise1), Task::await($promise2));
    }

    public function provideHostnames(): array
    {
        return [
            ["google.com"],
            ["github.com"],
            ["stackoverflow.com"],
            ["blog.kelunik.com"], /* that's a CNAME to GH pages */
            ["localhost"],
            ["192.168.0.1"],
            ["::1"],
        ];
    }

    public function provideServers(): array
    {
        return [
            ["8.8.8.8"],
            ["8.8.8.8:53"],
        ];
    }
}
