<?php

declare(strict_types=1);

namespace Kayra\Cache;

use Kayra\Encryption\Encrypter;
use RuntimeException;

/**
 * Cache entries as files.
 *
 * Two things are worth knowing about the on-disk format.
 *
 * **A key never becomes a path.** The filename is a hash, so a key of
 * `../../.env` addresses a file inside the cache directory like any other. The
 * same rule the session and rate-limiter stores follow.
 *
 * **Payloads are signed when a key is available.** Reading a cache entry means
 * `unserialize()`, and unserialising something an attacker wrote is remote code
 * execution. A signature means a file edited on disk is discarded as a miss
 * rather than trusted. Signing is skipped only when the application has no
 * encrypter -- a bare script, a test -- and in that case the cache is no more
 * exposed than the directory it sits in.
 */
final class FileStore implements Store
{
    private const SIGNED_PREFIX = "kc1\n";

    public function __construct(
        private readonly string $directory,
        private readonly ?Encrypter $encrypter = null,
    ) {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Unable to create the cache directory [{$this->directory}].");
        }
    }

    public function get(string $key): mixed
    {
        $path = $this->path($key);
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $payload = $this->unwrap($contents);

        if ($payload === null) {
            // Tampered, truncated, or written by a different key. Treat it as a
            // miss and remove it rather than leaving it to fail every read.
            @unlink($path);

            return null;
        }

        [$expires, $serialised] = $payload;

        if ($expires !== 0 && $expires <= time()) {
            @unlink($path);

            return null;
        }

        try {
            return unserialize($serialised);
        } catch (\Throwable) {
            @unlink($path);

            return null;
        }
    }

    public function put(string $key, mixed $value, int $seconds): bool
    {
        // Exactly zero means no expiry; a negative lifetime is a time in
        // the past, which is what it says.
        $expires = $seconds === 0 ? 0 : time() + $seconds;
        $body = $expires . "\n" . serialize($value);

        $path = $this->path($key);
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        // Written aside and renamed: a concurrent reader sees either the old
        // entry or the new one, never half of one.
        if (@file_put_contents($temporary, $this->wrap($body), LOCK_EX) === false) {
            return false;
        }

        @chmod($temporary, 0o600);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    public function add(string $key, mixed $value, int $seconds): bool
    {
        // Not atomic against a concurrent writer, and saying so is better than
        // implying a guarantee the filesystem is not giving. Callers that need
        // a real lock should use the rate limiter's flock path or a store that
        // supports SETNX.
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

        // Preserve the remaining lifetime rather than restarting it.
        $remaining = $this->secondsLeft($key);

        return $this->put($key, $new, $remaining) ? $new : false;
    }

    public function forget(string $key): bool
    {
        $path = $this->path($key);

        return !is_file($path) || @unlink($path);
    }

    public function flush(): bool
    {
        $ok = true;

        foreach (glob($this->directory . '/c_*') ?: [] as $file) {
            $ok = @unlink($file) && $ok;
        }

        return $ok;
    }

    /**
     * Remove every expired entry.
     *
     * Nothing calls this automatically: a cache that sweeps itself on read
     * makes one unlucky request pay for everyone. Wire it to a scheduled task.
     *
     * @return int Number removed.
     */
    public function purge(): int
    {
        $removed = 0;
        $now = time();

        foreach (glob($this->directory . '/c_*') ?: [] as $file) {
            $contents = @file_get_contents($file);
            $payload = $contents === false ? null : $this->unwrap($contents);

            if ($payload === null || ($payload[0] !== 0 && $payload[0] <= $now)) {
                $removed += (int) @unlink($file);
            }
        }

        return $removed;
    }

    /* --------------------------------------------------------------------
     | Format
     * -------------------------------------------------------------------- */

    private function wrap(string $body): string
    {
        if ($this->encrypter === null) {
            return $body;
        }

        return self::SIGNED_PREFIX . $this->encrypter->sign($body) . "\n" . $body;
    }

    /**
     * @return array{0: int, 1: string}|null [expiry, serialised value], or null
     *                                       when the file cannot be trusted.
     */
    private function unwrap(string $contents): ?array
    {
        if (str_starts_with($contents, self::SIGNED_PREFIX)) {
            $rest = substr($contents, strlen(self::SIGNED_PREFIX));
            $split = strpos($rest, "\n");

            if ($split === false) {
                return null;
            }

            $signature = substr($rest, 0, $split);
            $body = substr($rest, $split + 1);

            // No encrypter but a signed file: the key was removed or rotated.
            // Refusing is the safe direction -- the alternative is unserialising
            // something nothing can vouch for.
            if ($this->encrypter === null || !$this->encrypter->verify($body, $signature)) {
                return null;
            }

            $contents = $body;
        } elseif ($this->encrypter !== null) {
            // An encrypter is configured, so every entry this store wrote is
            // signed. An unsigned one was written by something else.
            return null;
        }

        $split = strpos($contents, "\n");

        if ($split === false) {
            return null;
        }

        $expires = substr($contents, 0, $split);

        if (!ctype_digit($expires)) {
            return null;
        }

        return [(int) $expires, substr($contents, $split + 1)];
    }

    /**
     * Seconds until the entry expires; 0 when it does not, or is already gone.
     */
    private function secondsLeft(string $key): int
    {
        $contents = @file_get_contents($this->path($key));
        $payload = $contents === false ? null : $this->unwrap($contents);

        if ($payload === null || $payload[0] === 0) {
            return 0;
        }

        return max(1, $payload[0] - time());
    }

    /**
     * The file a key maps to.
     *
     * Hashed, so no key can escape the directory: a key of `../../.env`
     * addresses a file inside it like any other. The `c_` prefix keeps flush()
     * and purge() from touching anything this store did not write.
     */
    private function path(string $key): string
    {
        return $this->directory . '/c_' . hash('xxh128', $key);
    }
}
