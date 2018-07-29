<?php

namespace Amp\Dns;

use Amp\Loop;

const LOOP_STATE_IDENTIFIER = Resolver::class;

/**
 * Retrieve the application-wide dns resolver instance.
 *
 * @param \Amp\Dns\Resolver $resolver Optionally specify a new default dns resolver instance
 *
 * @return \Amp\Dns\Resolver Returns the application-wide dns resolver instance
 */
function resolver(Resolver $resolver = null): Resolver {
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
function driver(): Resolver {
    return new BasicResolver;
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
 * @throws ResolutionException
 *
 * @see Resolver::resolve()
 */
function resolve(string $name, int $typeRestriction = null): array {
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
 * @throws ResolutionException
 *
 * @see Resolver::query()
 */
function query(string $name, int $type): array {
    return resolver()->query($name, $type);
}
