<?php

namespace Amp\Dns;

final class UnixConfigLoader implements ConfigLoader
{
    private $path;
    private $hostLoader;

    public function __construct(string $path = "/etc/resolv.conf", HostLoader $hostLoader = null)
    {
        $this->path = $path;
        $this->hostLoader = $hostLoader ?? new HostLoader;
    }

    public function loadConfig(): Config
    {
        $path = $this->path;
        $nameservers = [];
        $timeout = 3000;
        $attempts = 2;

        $fileContent = @\file_get_contents($path);
        if ($fileContent === false) {
            throw new ConfigException("Could not read configuration file ({$path}): " . \error_get_last()["message"]);
        }

        $lines = \explode("\n", $fileContent);

        foreach ($lines as $line) {
            $line = \preg_split('#\s+#', $line, 2);

            if (\count($line) !== 2) {
                continue;
            }

            [$type, $value] = $line;

            if ($type === "nameserver") {
                $value = \trim($value);
                $ip = @\inet_pton($value);

                if ($ip === false) {
                    continue;
                }

                if (isset($ip[15])) { // IPv6
                    $nameservers[] = "[{$value}]:53";
                } else { // IPv4
                    $nameservers[] = "{$value}:53";
                }
            } elseif ($type === "options") {
                $optline = \preg_split('#\s+#', $value, 2);

                if (\count($optline) !== 2) {
                    continue;
                }

                [$option, $value] = $optline;

                switch ($option) {
                    case "timeout":
                        $timeout = (int) $value;
                        break;

                    case "attempts":
                        $attempts = (int) $value;
                }
            }
        }

        $hosts = $this->hostLoader->loadHosts();

        return new Config($nameservers, $hosts, $timeout, $attempts);
    }
}
