<?php

namespace Amp\Dns;

const LOOP_STATE_IDENTIFIER = Resolver::class;

/**
 * Retrieve the application-wide dns resolver instance.
 *
 * @param \Amp\Dns\Resolver $resolver Optionally specify a new default dns resolver instance
 *
 * @return \Amp\Dns\Resolver Returns the application-wide dns resolver instance
 */
function resolver(Resolver $resolver = null): Resolver
{
    if ($resolver === null) {
        $resolver = Loop::getState(LOOP_STATE_IDENTIFIER);

        if ($resolver) {
            return $resolver;
        }

        $resolver = driver();
    }

    Loop::setState(LOOP_STATE_IDENTIFIER, $resolver);

    return $resolver;
}

/**
 * Create a new dns resolver best-suited for the current environment.
 *
 * @return \Amp\Dns\Resolver
 */
function driver(): Resolver
{
    return new Rfc1035Resolver;
}

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
 *
 * @see Resolver::resolve()
 */
function resolve(string $name, int $typeRestriction = null): array
{
    return resolver()->resolve($name, $typeRestriction);
}

/**
 * Query specific DNS records.
 *
 * @param string $name Record to question, A, AAAA and PTR queries are automatically normalized.
 * @param int    $type Use constants of Amp\Dns\Record.
 *
 * @return Record[]
 *
 * @throws DnsException
 *
 * @see Resolver::query()
 */
function query(string $name, int $type): array
{
    return resolver()->query($name, $type);
}

/**
 * Checks whether a string is a valid DNS name.
 *
 * @param string $name String to check.
 *
 * @return bool
 */
function isValidDnsName(string $name): bool
{
    try {
        normalizeDnsName($name);
        return true;
    } catch (InvalidDnsNameException $e) {
        return false;
    }
}

/**
 * Normalizes a DNS name and automatically checks it for validity.
 *
 * @param string $name DNS name.
 *
 * @return string Normalized DNS name.
 *
 * @throws InvalidDnsNameException If an invalid name or an IDN name without ext/intl being installed has been passed.
 */
function normalizeDnsName(string $name): string
{
    static $pattern = '/^(?<name>[a-z0-9]([a-z0-9-_]{0,61}[a-z0-9])?)(\.(?&name))*$/i';

    if (\defined('INTL_IDNA_VARIANT_UTS46') && \function_exists('idn_to_ascii')) {
        if (false === $result = \idn_to_ascii($name, 0, \INTL_IDNA_VARIANT_UTS46)) {
            throw new InvalidDnsNameException("Name '{$name}' could not be processed for IDN.");
        }

        $name = $result;
    } else {
        if (\preg_match('/[\x80-\xff]/', $name)) {
            throw new InvalidDnsNameException(
                "Name '{$name}' contains non-ASCII characters and IDN support is not available. " .
                "Verify that ext/intl is installed for IDN support and that ICU is at least version 4.6."
            );
        }
    }

    if (isset($name[253]) || !\preg_match($pattern, $name)) {
        throw new InvalidDnsNameException("Name '{$name}' is not a valid hostname.");
    }

    return $name;
}
