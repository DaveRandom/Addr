<?php

namespace Amp\Dns;

interface Resolver
{
    /**
     * Resolves a hostname name to a set of IP addresses [hostname as defined by RFC 3986].
     *
     * A null `$ttl` value indicates the DNS name was resolved from the cache or the local hosts file.
     *
     * @param string $name The hostname to resolve.
     * @param int    $typeRestriction Optional type restriction to `Record::A` or `Record::AAAA`, otherwise `null`.
     *
     * @return Record[]
     *
     * @throws DnsException
     */
    public function resolve(string $name, int $typeRestriction = null): array;

    /**
     * Query specific DNS records.
     *
     * @param string $name Record to question, A, AAAA and PTR queries are automatically normalized.
     * @param int    $type Use constants of Amp\Dns\Record.
     *
     * @return Record[]
     *
     * @throws DnsException
     */
    public function query(string $name, int $type): array;
}
