<?php

namespace Amp\Dns;

use Amp\Failure;
use Amp\Promise;
use Amp\Success;

class BlockingConfigFileReader implements ConfigFileReader {
    public function read(string $path): Promise {
        \set_error_handler(function (int $errno, string $message) use ($path) {
            throw new ConfigException("Could not read configuration file '{$path}' ({$errno}) $message");
        });

        try {
            // Blocking file access, but this file should be local and usually loaded only once.
            $fileContent = \file_get_contents($path);
        } catch (ConfigException $exception) {
            return new Failure($exception);
        } finally {
            \restore_error_handler();
        }

        return new Success($fileContent);
    }
}
