<?php

namespace Amp\Dns;

interface ConfigLoader
{
    /**
     * @return Config
     *
     * @throws ConfigException
     */
    public function loadConfig(): Config;
}
