<?php

require __DIR__ . "/_bootstrap.php";

use Amp\Dns;

$customConfigLoader = new class implements Dns\ConfigLoader
{
    public function loadConfig(): Dns\Config
    {
        $hosts = (new Dns\HostLoader)->loadHosts();

        return new Dns\Config([
            "1.1.1.1:53",
            "8.8.4.4:53",
            "8.8.8.8:53",
            "[2001:4860:4860::8888]:53",
        ], $hosts, $timeout = 5000, $attempts = 3);
    }
};

Dns\resolver(new Dns\Rfc1035Resolver(null, $customConfigLoader));

$hostname = "amphp.org";

try {
    pretty_print_records($hostname, Dns\resolve($hostname));
} catch (Dns\DnsException $e) {
    pretty_print_error($hostname, $e);
}
