<?php

declare(strict_types=1);

namespace Kayra\Auth;

use Kayra\Session\Session;
use SensitiveParameter;

/**
 * Session-backed authentication.
 *
 * The session stores the user's id and a fingerprint of their password hash.
 * Both are checked on every request, which is what makes "log out everywhere"
 * fall out of changing a password rather than needing separate bookkeeping.
 */
final class SessionGuard implements Guard
{
    private const ID_KEY = '_auth.id';
    private const HASH_KEY = '_auth.hash';

    private ?Authenticatable $user = null;

    /** Distinguishes "not looked up yet" from "looked up, nobody there". */
    private bool $resolved = false;

    public function __construct(
        private readonly UserProvider $provider,
        private readonly Session $session,
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

        $id = $this->session->get(self::ID_KEY);

        if (!is_int($id) && !is_string($id)) {
            return null;
        }

        $user = $this->provider->retrieveById($id);

        if ($user === null) {
            // The account was deleted while the session lived on.
            $this->clearSession();

            return null;
        }

        // Reject sessions issued under a previous password.
        $expected = $this->session->get(self::HASH_KEY);

        if (!is_string($expected) || !hash_equals($user->getAuthPasswordHashSnapshot(), $expected)) {
            $this->clearSession();

            return null;
        }

        return $this->user = $user;
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * Verify credentials without logging anyone in.
     *
     * @param array<string, mixed> $credentials
     */
    public function validate(#[SensitiveParameter] array $credentials): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        return $this->provider->validateCredentials($user, $credentials);
    }

    /**
     * Verify credentials and, on success, log the user in.
     *
     * @param array<string, mixed> $credentials
     */
    public function attempt(#[SensitiveParameter] array $credentials): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        if (!$this->provider->validateCredentials($user, $credentials)) {
            return false;
        }

        // $user is non-null here: validateCredentials() returns false for null.
        /** @var Authenticatable $user */
        $this->provider->rehashPasswordIfRequired($user, $credentials);
        $this->login($user);

        return true;
    }

    /**
     * Establish a session for a user.
     */
    public function login(Authenticatable $user): void
    {
        // Session fixation defence: an attacker who planted a session id before
        // login must not still hold a valid one after it.
        $this->session->regenerate();

        $this->session->put(self::ID_KEY, $user->getAuthIdentifier());
        $this->session->put(self::HASH_KEY, $user->getAuthPasswordHashSnapshot());

        $this->user = $user;
        $this->resolved = true;
    }

    public function logout(): void
    {
        $this->clearSession();

        // A new id as well as new contents, so the old cookie is worthless.
        $this->session->invalidate();
    }

    private function clearSession(): void
    {
        $this->session->forget(self::ID_KEY);
        $this->session->forget(self::HASH_KEY);

        $this->user = null;
        $this->resolved = true;
    }
}
