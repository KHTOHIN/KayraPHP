<?php

declare(strict_types=1);

namespace Kayra\Auth;

use SensitiveParameter;

/**
 * Where users come from.
 *
 * Separating this from the guard is what lets session auth and token auth share
 * one user store, and lets that store be a model, a table, or something else
 * entirely without either guard knowing.
 */
interface UserProvider
{
    public function retrieveById(int|string $identifier): ?Authenticatable;

    /**
     * Find a user by the non-secret parts of a credential set.
     *
     * Must ignore the password: matching on it here would turn the lookup into
     * a non-constant-time comparison.
     *
     * @param array<string, mixed> $credentials
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable;

    /**
     * Check a password against the stored hash, in constant time.
     *
     * @param array<string, mixed> $credentials
     */
    public function validateCredentials(
        ?Authenticatable $user,
        #[SensitiveParameter] array $credentials,
    ): bool;

    /**
     * Re-hash a user's password when the cost policy has moved on.
     */
    public function rehashPasswordIfRequired(
        Authenticatable $user,
        #[SensitiveParameter] array $credentials,
    ): void;
}
