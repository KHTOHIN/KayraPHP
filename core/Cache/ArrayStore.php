<?php

declare(strict_types=1);

namespace Kayra\Cache;

/**
 * An in-process cache that lasts as long as the object does.
 *
 * The right default for tests, and genuinely useful in front of a slower store
 * for values a single request reads more than once.
 *
 * Nothing is serialised, so a stored object comes back as the same instance --
 * a difference from every other driver, and the reason this one is not a
 * substitute for them in a test that cares about round-tripping.
 */
final class ArrayStore implements Store
{
    /** @var array<string, array{value: mixed, expires: int}> */
    private array $entries = [];

    public function get(string $key): mixed
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['expires'] !== 0 && $entry['expires'] <= time()) {
            unset($this->entries[$key]);

            return null;
        }

        return $entry['value'];
    }

    public function put(string $key, mixed $value, int $seconds): bool
    {
        $this->entries[$key] = [
            'value'   => $value,
            // Exactly zero means no expiry; a negative lifetime is a time
            // in the past, which is what it says.
            'expires' => $seconds === 0 ? 0 : time() + $seconds,
        ];

        return true;
    }

    public function add(string $key, mixed $value, int $seconds): bool
    {
        // Single-process and single-threaded: this really is atomic.
        if ($this->get($key) !== null) {
            return false;
        }

        return $this->put($key, $value, $seconds);
    }

    public function increment(string $key, int $by = 1): int|false
    {
        $current = $this->get($key);

        if ($current !== null && !is_int($current)) {
            return false;
        }

        $new = (int) $current + $by;

        // Keeps the original expiry: incrementing a counter should not extend
        // the window it is counting within.
        $expires = $this->entries[$key]['expires'] ?? 0;
        $this->entries[$key] = ['value' => $new, 'expires' => $expires];

        return $new;
    }

    public function forget(string $key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->entries = [];

        return true;
    }
}
