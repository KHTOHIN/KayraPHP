<?php

declare(strict_types=1);

namespace Kayra\Cache;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\SimpleCache\CacheInterface;
use Traversable;

/**
 * A PSR-16 cache over a {@see Store}.
 *
 * This is where the specification lives -- key validation, DateInterval TTLs,
 * the multiple-key methods -- so a driver only has to know how to read and
 * write a string key.
 *
 * Beyond PSR-16 it adds the handful of methods that make a cache pleasant:
 * {@see remember()} above all, because "read it, and compute it if it is not
 * there" is what almost every cache call actually is, and writing it by hand
 * is how the check and the write drift apart.
 */
final class Repository implements CacheInterface
{
    /**
     * Marks a genuinely cached null.
     *
     * Without it, `get('key', 'fallback')` cannot tell "not cached" from
     * "cached as null", and a null-returning callback would be recomputed on
     * every request -- the exact case caching was meant to fix.
     */
    private const NULL_SENTINEL = "\0kayra:null\0";

    public function __construct(
        private readonly Store $store,
        private readonly int $defaultTtl = 3600,
    ) {
    }

    /* --------------------------------------------------------------------
     | PSR-16
     * -------------------------------------------------------------------- */

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->store->get($this->validated($key));

        return match (true) {
            $value === null                 => $default,
            $value === self::NULL_SENTINEL  => null,
            default                         => $value,
        };
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $seconds = $this->seconds($ttl);

        // PSR-16: a TTL that has already elapsed means delete, not store. Note
        // that this makes set($key, $value, 0) a deletion -- use forever() for
        // an entry with no expiry.
        if ($seconds !== null && $seconds <= 0) {
            return $this->store->forget($this->validated($key));
        }

        return $this->store->put(
            $this->validated($key),
            $value ?? self::NULL_SENTINEL,
            $seconds ?? $this->defaultTtl,
        );
    }

    public function delete(string $key): bool
    {
        return $this->store->forget($this->validated($key));
    }

    public function clear(): bool
    {
        return $this->store->flush();
    }

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $results = [];

        foreach ($this->toArray($keys) as $key) {
            $results[$key] = $this->get($key, $default);
        }

        return $results;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        $ok = true;

        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;

        foreach ($this->toArray($keys) as $key) {
            $ok = $this->delete($key) && $ok;
        }

        return $ok;
    }

    public function has(string $key): bool
    {
        return $this->store->get($this->validated($key)) !== null;
    }

    /* --------------------------------------------------------------------
     | Beyond the specification
     * -------------------------------------------------------------------- */

    /**
     * Read, or compute and store.
     *
     * A cached null counts as a hit, so a callback that legitimately returns
     * null runs once rather than on every request.
     *
     * A TTL of 0 follows set(): the value is computed and not stored. For an
     * entry that never expires use {@see rememberForever()}.
     *
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    public function remember(string $key, DateInterval|int|null $ttl, Closure $callback): mixed
    {
        $cached = $this->store->get($this->validated($key));

        if ($cached !== null) {
            /** @var T */
            return $cached === self::NULL_SENTINEL ? null : $cached;
        }

        $value = $callback();

        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * {@see remember()} with no expiry.
     *
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    public function rememberForever(string $key, Closure $callback): mixed
    {
        $cached = $this->store->get($this->validated($key));

        if ($cached !== null) {
            /** @var T */
            return $cached === self::NULL_SENTINEL ? null : $cached;
        }

        $value = $callback();

        $this->forever($key, $value);

        return $value;
    }

    /**
     * Store with no expiry.
     *
     * Deliberately not set($key, $value, 0): PSR-16 reads a non-positive TTL as
     * "already expired, remove it", which is the exact opposite of this. The
     * store's own zero means "no expiry", so this talks to it directly.
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->store->put($this->validated($key), $value ?? self::NULL_SENTINEL, 0);
    }

    /**
     * Store only if the key is absent.
     *
     * The file driver cannot make this atomic; see {@see FileStore::add()}.
     */
    public function add(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $seconds = $this->seconds($ttl);

        // An entry that would already have expired is not worth adding, and
        // storing it with the store's "no expiry" zero would be worse.
        if ($seconds !== null && $seconds <= 0) {
            return false;
        }

        return $this->store->add(
            $this->validated($key),
            $value ?? self::NULL_SENTINEL,
            $seconds ?? $this->defaultTtl,
        );
    }

    /**
     * Read and remove in one step.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->delete($key);

        return $value;
    }

    /**
     * @return int|false False when the entry exists but is not a number.
     */
    public function increment(string $key, int $by = 1): int|false
    {
        return $this->store->increment($this->validated($key), $by);
    }

    /**
     * @return int|false False when the entry exists but is not a number.
     */
    public function decrement(string $key, int $by = 1): int|false
    {
        return $this->store->increment($this->validated($key), -$by);
    }

    /**
     * The underlying driver, for the things only it can do.
     */
    public function store(): Store
    {
        return $this->store;
    }

    /* --------------------------------------------------------------------
     | Specification plumbing
     * -------------------------------------------------------------------- */

    /**
     * PSR-16 reserves {}()/\@: in keys and requires an exception, not a
     * silently mangled key. Rejecting loudly also keeps a caller from
     * discovering months later that two keys were colliding.
     *
     * @throws InvalidArgumentException
     */
    private function validated(string $key): string
    {
        if ($key === '') {
            throw new InvalidArgumentException('A cache key cannot be empty.');
        }

        if (preg_match('/[{}()\/\\\\@:]/', $key) === 1) {
            throw new InvalidArgumentException(
                "The cache key [{$key}] contains a character reserved by PSR-16: one of {}()/\\@:",
            );
        }

        return $key;
    }

    /**
     * Null means "the configured default"; anything else is seconds.
     */
    private function seconds(DateInterval|int|null $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if (is_int($ttl)) {
            return $ttl;
        }

        // Resolved against a real date, so a month or a year is the length it
        // actually is rather than an approximation.
        $now = new DateTimeImmutable();

        return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
    }

    /**
     * @param iterable<string> $keys
     *
     * @return list<string>
     */
    private function toArray(iterable $keys): array
    {
        $list = $keys instanceof Traversable ? iterator_to_array($keys, false) : $keys;

        return array_values(array_map(strval(...), $list));
    }
}
