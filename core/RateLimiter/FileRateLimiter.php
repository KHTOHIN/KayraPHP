<?php

declare(strict_types=1);

namespace Kayra\RateLimiter;

use RuntimeException;

/**
 * File-backed fixed-window rate limiter.
 *
 * Adequate for a single host. A multi-server deployment needs a shared store
 * (Redis) or each node enforces its own separate budget — `kayra doctor`
 * reports which limiter is active so that is visible rather than assumed.
 *
 * Counters are incremented under an exclusive lock, so two concurrent requests
 * cannot both read the same count and each write back count+1.
 */
final class FileRateLimiter implements RateLimiter
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Unable to create rate limiter directory [{$this->directory}].");
        }
    }

    public function hit(string $key, int $decaySeconds): int
    {
        $path = $this->path($key);
        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the rate limiter counter.');
        }

        try {
            // Without the lock, concurrent requests read the same value and the
            // limit silently allows more than it should.
            flock($handle, LOCK_EX);

            $window = $this->readWindow($handle);
            $now = time();

            if ($window === null || $window['expires'] <= $now) {
                $window = ['count' => 0, 'expires' => $now + $decaySeconds];
            }

            $window['count']++;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $window['count'] . ':' . $window['expires']);
            fflush($handle);

            return $window['count'];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function attempts(string $key): int
    {
        $window = $this->read($key);

        return $window === null ? 0 : $window['count'];
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    public function availableIn(string $key): int
    {
        $window = $this->read($key);

        return $window === null ? 0 : max(0, $window['expires'] - time());
    }

    public function clear(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return array{count: int, expires: int}|null
     */
    private function read(string $key): ?array
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false || !str_contains($contents, ':')) {
            return null;
        }

        [$count, $expires] = explode(':', $contents, 2);

        if ((int) $expires <= time()) {
            return null;
        }

        return ['count' => (int) $count, 'expires' => (int) $expires];
    }

    /**
     * @param resource $handle
     * @return array{count: int, expires: int}|null
     */
    private function readWindow($handle): ?array
    {
        rewind($handle);
        $contents = stream_get_contents($handle);

        if ($contents === false || !str_contains($contents, ':')) {
            return null;
        }

        [$count, $expires] = explode(':', $contents, 2);

        return ['count' => (int) $count, 'expires' => (int) $expires];
    }

    /**
     * Hash the key so an arbitrary string — a path, an IP — can never become a
     * directory traversal.
     */
    private function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'rl_' . hash('xxh128', $key);
    }
}
