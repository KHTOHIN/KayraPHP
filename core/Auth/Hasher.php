<?php

declare(strict_types=1);

namespace Kayra\Auth;

use RuntimeException;
use SensitiveParameter;

/**
 * Password hashing.
 *
 * Wraps PHP's password_* functions rather than reimplementing anything: they
 * are the correct primitive, they salt automatically, and they carry the
 * algorithm and cost inside the hash so it can be upgraded later without a
 * migration.
 *
 * The default is bcrypt rather than argon2id only because bcrypt is available
 * on every PHP build; pass 'argon2id' where it is compiled in.
 */
final class Hasher
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly string $algorithm = PASSWORD_BCRYPT,
        private readonly array $options = ['cost' => 12],
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $driver = (string) ($config['driver'] ?? 'bcrypt');

        return match ($driver) {
            'argon2i'  => new self(PASSWORD_ARGON2I, self::argonOptions($config)),
            'argon2id' => new self(PASSWORD_ARGON2ID, self::argonOptions($config)),
            default    => new self(PASSWORD_BCRYPT, ['cost' => (int) ($config['cost'] ?? 12)]),
        };
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, int>
     */
    private static function argonOptions(array $config): array
    {
        return [
            'memory_cost' => (int) ($config['memory'] ?? 65536),
            'time_cost'   => (int) ($config['time'] ?? 4),
            'threads'     => (int) ($config['threads'] ?? 1),
        ];
    }

    public function make(#[SensitiveParameter] string $password): string
    {
        if ($password === '') {
            throw new RuntimeException('Refusing to hash an empty password.');
        }

        // bcrypt silently truncates at 72 bytes, so a longer password would
        // "work" while only its first 72 bytes mattered. Say so instead.
        if ($this->algorithm === PASSWORD_BCRYPT && strlen($password) > 72) {
            throw new RuntimeException(
                'bcrypt cannot hash passwords longer than 72 bytes; it would silently '
                . 'ignore the remainder. Use argon2id, or limit the length at validation.',
            );
        }

        // password_hash() throws on a bad algorithm and cannot return an empty
        // string, so there is nothing left to check here.
        return password_hash($password, $this->algorithm, $this->options);
    }

    /**
     * Verify a password against a hash, in constant time.
     */
    public function check(#[SensitiveParameter] string $password, string $hash): bool
    {
        if ($hash === '' || $password === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    /**
     * Whether the hash was made with weaker parameters than the current policy.
     *
     * Call after a successful login and re-hash if true: that is how a cost
     * increase reaches existing users without asking anyone to reset.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }

    /**
     * A hash of a random value, for comparing against when no user was found.
     *
     * Without this, a login attempt for a non-existent account returns much
     * faster than one for a real account, and that difference is enough to
     * enumerate valid usernames.
     */
    public function dummyHash(): string
    {
        static $dummy = null;

        return $dummy ??= $this->make(bin2hex(random_bytes(16)));
    }

    /**
     * Describe the hash's algorithm and parameters, for diagnostics.
     *
     * @return array{algo: string, options: array<string, mixed>}
     */
    public function info(string $hash): array
    {
        $info = password_get_info($hash);

        return [
            'algo'    => (string) ($info['algoName'] ?? 'unknown'),
            'options' => is_array($info['options'] ?? null) ? $info['options'] : [],
        ];
    }
}
