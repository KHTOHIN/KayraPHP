<?php

declare(strict_types=1);

namespace Kayra\Auth;

use Kayra\Database\Connection;
use Kayra\Database\Model;
use SensitiveParameter;

/**
 * Password reset.
 *
 * Four properties matter here, and each one is a real attack if missed:
 *
 *  1. **Tokens are stored hashed.** A leaked database must not hand over the
 *     ability to reset every account.
 *  2. **Tokens expire.** A reset link found in an old inbox years later is
 *     not a valid credential.
 *  3. **Tokens are single-use.** The record is deleted on success, so a link
 *     in a forwarded email or a browser history cannot be replayed.
 *  4. **Requesting a reset never reveals whether the address exists.** The
 *     caller gets the same answer either way; only the mail differs.
 */
final class PasswordBroker
{
    public function __construct(
        private readonly UserProvider $users,
        private readonly Connection $connection,
        private readonly Hasher $hasher,
        private readonly string $table = 'password_resets',
        private readonly int $expiresInSeconds = 3600,
    ) {
    }

    /**
     * Issue a reset token for an email address.
     *
     * Returns the plaintext token when the account exists, and null when it
     * does not — callers must respond identically in both cases. The token is
     * returned rather than mailed because delivery is the application's choice.
     */
    public function createToken(string $email): ?string
    {
        $user = $this->users->retrieveByCredentials(['email' => $email]);

        if ($user === null) {
            return null;
        }

        $plain = bin2hex(random_bytes(32));

        // One outstanding token per address: issuing a new link must retire the
        // previous one rather than leaving both live.
        $this->connection->table($this->table)->where('email', $email)->delete();

        $this->connection->table($this->table)->insert([
            'email'      => $email,
            'token'      => $this->hashToken($plain),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $plain;
    }

    /**
     * Whether a token is currently valid for an address.
     */
    public function validateToken(string $email, string $token): bool
    {
        $record = $this->connection->table($this->table)->where('email', $email)->first();

        if ($record === null) {
            return false;
        }

        $stored = (string) ($record['token'] ?? '');
        $createdAt = (string) ($record['created_at'] ?? '');

        if ($createdAt === '' || strtotime($createdAt) + $this->expiresInSeconds < time()) {
            $this->deleteToken($email);

            return false;
        }

        return hash_equals($stored, $this->hashToken($token));
    }

    /**
     * Consume a token and set a new password.
     *
     * @return bool False when the token is wrong, expired, or already used.
     */
    public function reset(string $email, string $token, #[SensitiveParameter] string $newPassword): bool
    {
        if (!$this->validateToken($email, $token)) {
            return false;
        }

        $user = $this->users->retrieveByCredentials(['email' => $email]);

        if (!$user instanceof Model) {
            return false;
        }

        $user->forceFill(['password' => $this->hasher->make($newPassword)])->save();

        // Single use. Deleting after the password is written means a failure
        // mid-way leaves the token usable rather than stranding the user.
        $this->deleteToken($email);

        return true;
    }

    public function deleteToken(string $email): void
    {
        $this->connection->table($this->table)->where('email', $email)->delete();
    }

    /**
     * Remove expired tokens.
     *
     * @return int Number removed.
     */
    public function purgeExpired(): int
    {
        return $this->connection->table($this->table)
            ->where('created_at', '<', gmdate('Y-m-d H:i:s', time() - $this->expiresInSeconds))
            ->delete();
    }

    /**
     * SHA-256 rather than a password hash: the token is already 256 bits of
     * randomness, so there is nothing to brute-force, and lookup must be a
     * plain indexed comparison rather than a per-row verify.
     */
    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
