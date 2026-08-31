<?php

declare(strict_types=1);

namespace Kayra\RateLimiter;

/**
 * Counts attempts against a key within a time window.
 */
interface RateLimiter
{
    /**
     * Record an attempt.
     *
     * @return int Attempts used in the current window, including this one.
     */
    public function hit(string $key, int $decaySeconds): int;

    public function attempts(string $key): int;

    public function tooManyAttempts(string $key, int $maxAttempts): bool;

    /**
     * Seconds until the current window resets.
     */
    public function availableIn(string $key): int;

    public function clear(string $key): void;
}
