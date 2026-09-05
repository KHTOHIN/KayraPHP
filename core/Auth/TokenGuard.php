<?php

declare(strict_types=1);

namespace Kayra\Auth;

use Kayra\Database\Model;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;

/**
 * Bearer-token authentication, for APIs.
 *
 * Tokens are stored hashed. A leaked database therefore yields no usable
 * tokens, exactly as it yields no usable passwords — the plaintext is shown
 * once, when the token is issued, and never again.
 */
final class TokenGuard implements Guard
{
    private ?Authenticatable $user = null;

    private bool $resolved = false;

    /**
     * @param class-string<Model> $tokenModel
     */
    public function __construct(
        private readonly UserProvider $provider,
        private readonly ServerRequestInterface $request,
        private readonly string $tokenModel,
        private readonly string $userKey = 'user_id',
        private readonly string $hashColumn = 'token',
    ) {
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        $token = $this->tokenFromRequest();

        if ($token === null) {
            return null;
        }

        // Look the token up by its hash: the plaintext is never stored, so this
        // is also the only way to find it.
        $record = $this->tokenModel::query()
            ->where($this->hashColumn, self::hash($token))
            ->first();

        if ($record === null) {
            return null;
        }

        $expiresAt = $record->getAttributes()['expires_at'] ?? null;

        if (is_string($expiresAt) && $expiresAt !== '' && strtotime($expiresAt) < time()) {
            return null;
        }

        $identifier = $record->getAttributes()[$this->userKey] ?? null;

        if (!is_int($identifier) && !is_string($identifier)) {
            return null;
        }

        return $this->user = $this->provider->retrieveById($identifier);
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function validate(#[SensitiveParameter] array $credentials): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        return $this->provider->validateCredentials($user, $credentials);
    }

    /**
     * Generate a token. The plaintext is returned once and never stored.
     *
     * @return array{plain: string, hash: string}
     */
    public static function generate(): array
    {
        $plain = bin2hex(random_bytes(32));

        return ['plain' => $plain, 'hash' => self::hash($plain)];
    }

    /**
     * Hash a token for storage and lookup.
     *
     * SHA-256 rather than a password hash: tokens are 256 bits of entropy, so
     * there is nothing to brute-force, and lookup has to be a plain indexed
     * equality check rather than a per-row verify.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function tokenFromRequest(): ?string
    {
        $header = $this->request->getHeaderLine('Authorization');

        if (stripos($header, 'bearer ') === 0) {
            $token = trim(substr($header, 7));

            return $token === '' ? null : $token;
        }

        return null;
    }
}
