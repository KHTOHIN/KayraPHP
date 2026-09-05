<?php

declare(strict_types=1);

namespace Kayra\Cache;

/**
 * Where cached values actually live.
 *
 * Deliberately narrower than PSR-16: no key validation, no DateInterval, no
 * iterables. {@see Repository} does all of that once, so a new driver is a
 * handful of methods rather than a re-implementation of the specification.
 *
 * A TTL of exactly 0 means "keep until something removes it". A negative one
 * is a lifetime that has already run out, so the entry is written expired and
 * the next read misses -- not the same thing, and collapsing the two is how a
 * value meant to be discarded gets stored permanently.
 */
interface Store
{
    /**
     * The value, or null when absent or expired.
     *
     * Null is indistinguishable from a stored null here on purpose; Repository
     * layers a sentinel on top for callers who need to tell them apart.
     */
    public function get(string $key): mixed;

    public function put(string $key, mixed $value, int $seconds): bool;

    /**
     * Store only if the key is absent.
     *
     * Separate from put() because a driver that can do it atomically should,
     * and one that cannot should at least be honest about the race.
     */
    public function add(string $key, mixed $value, int $seconds): bool;

    /**
     * @return int|false The new value, or false when the entry is not countable.
     */
    public function increment(string $key, int $by = 1): int|false;

    public function forget(string $key): bool;

    /**
     * Remove everything this store owns.
     */
    public function flush(): bool;
}
