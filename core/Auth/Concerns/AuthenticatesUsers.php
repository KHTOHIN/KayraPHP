<?php

declare(strict_types=1);

namespace Kayra\Auth\Concerns;

/**
 * Makes a model satisfy {@see \Kayra\Auth\Authenticatable}.
 *
 * Assumes the conventional columns; override any method whose column differs.
 */
trait AuthenticatesUsers
{
    public function getAuthIdentifier(): int|string
    {
        $key = $this->getKey();

        if (!is_int($key) && !is_string($key)) {
            throw new \LogicException(
                static::class . ' has no usable primary key value for authentication.',
            );
        }

        return $key;
    }

    public function getAuthPassword(): string
    {
        $password = $this->getAttributes()['password'] ?? '';

        return is_string($password) ? $password : '';
    }

    /**
     * A short fingerprint of the current password hash.
     *
     * Stored in the session next to the user id. When the password changes the
     * fingerprint changes, so every session created under the old password
     * stops validating — which is the behaviour a user expects after changing
     * a password they think was compromised.
     */
    public function getAuthPasswordHashSnapshot(): string
    {
        return substr(hash('sha256', $this->getAuthPassword()), 0, 16);
    }
}
