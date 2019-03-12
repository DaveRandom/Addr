<?php

namespace Amp\Dns;

use Amp\WindowsRegistry\MissingKeyException;
use Amp\WindowsRegistry\QueryException;
use Amp\WindowsRegistry\WindowsRegistry;

final class WindowsConfigLoader implements ConfigLoader
{
    private $hostLoader;

    public function __construct(HostLoader $hostLoader = null)
    {
        $this->hostLoader = $hostLoader ?? new HostLoader;
    }

    public function loadConfig(): Config
    {
        $keys = [
            "HKEY_LOCAL_MACHINE\\SYSTEM\\CurrentControlSet\\Services\\Tcpip\\Parameters\\NameServer",
            "HKEY_LOCAL_MACHINE\\SYSTEM\\CurrentControlSet\\Services\\Tcpip\\Parameters\\DhcpNameServer",
        ];

        $registry = new WindowsRegistry;
        $nameserver = "";

        while ($nameserver === "" && ($key = \array_shift($keys))) {
            try {
                $nameserver = $registry->read($key);
            } catch (MissingKeyException $e) {
                // retry other possible locations
            } catch (QueryException $e) {
                throw new ConfigException("Error while querying the Windows Registry", $e);
            }
        }

        if ($nameserver === "") {
            $interfaces = "HKEY_LOCAL_MACHINE\\SYSTEM\\CurrentControlSet\\Services\\Tcpip\\Parameters\\Interfaces";

            try {
                $subKeys = $registry->listKeys($interfaces);
            } catch (QueryException $e) {
                throw new ConfigException("Error while querying the Windows Registry", $e);
            }

            foreach ($subKeys as $key) {
                foreach (["NameServer", "DhcpNameServer"] as $property) {
                    try {
                        $nameserver = $registry->read("{$key}\\{$property}");

                        if ($nameserver !== "") {
                            break 2;
                        }
                    } catch (MissingKeyException $e) {
                        // retry other possible locations
                    } catch (QueryException $e) {
                        throw new ConfigException("Error while querying the Windows Registry", $e);
                    }
                }
            }
        }

        if ($nameserver === "") {
            throw new ConfigException("Could not find a nameserver in the Windows Registry");
        }

        $nameservers = [];

        // Microsoft documents space as delimiter, AppVeyor uses comma, we just accept both
        foreach (\explode(" ", \strtr($nameserver, ",", " ")) as $nameserver) {
            $nameserver = \trim($nameserver);
            $ip = @\inet_pton($nameserver);

            if ($ip === false) {
                continue;
            }

            if (isset($ip[15])) { // IPv6
                $nameservers[] = "[{$nameserver}]:53";
            } else { // IPv4
                $nameservers[] = "{$nameserver}:53";
            }
        }

        $hosts = $this->hostLoader->loadHosts();

        return new Config($nameservers, $hosts);
    }
}
