<?php

declare(strict_types=1);

namespace Kayra\Auth;

use Kayra\Database\Model;
use RuntimeException;
use SensitiveParameter;

/**
 * Users stored as models.
 *
 * @template TUser of Model&Authenticatable
 */
final class ModelUserProvider implements UserProvider
{
    /**
     * @param class-string<TUser> $model
     */
    public function __construct(
        private readonly string $model,
        private readonly Hasher $hasher,
    ) {
        if (!is_subclass_of($this->model, Model::class)) {
            throw new RuntimeException("[{$this->model}] must extend " . Model::class . '.');
        }

        if (!is_subclass_of($this->model, Authenticatable::class)) {
            throw new RuntimeException(
                "[{$this->model}] must implement " . Authenticatable::class
                . '. Add the ' . Concerns\AuthenticatesUsers::class . ' trait.',
            );
        }
    }

    public function retrieveById(int|string $identifier): ?Authenticatable
    {
        /** @var (Model&Authenticatable)|null $user */
        $user = $this->model::find($identifier);

        return $user;
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        // The password is a secret compared later, in constant time; using it
        // as a WHERE clause would both fail (it is hashed) and leak timing.
        $query = null;

        foreach ($credentials as $key => $value) {
            if ($key === 'password' || str_contains($key, 'password')) {
                continue;
            }

            $query = $query === null
                ? $this->model::where($key, $value)
                : $query->where($key, $value);
        }

        if ($query === null) {
            return null;
        }

        /** @var (Model&Authenticatable)|null $user */
        $user = $query->first();

        return $user;
    }

    public function validateCredentials(?Authenticatable $user, #[SensitiveParameter] array $credentials): bool
    {
        $password = $credentials['password'] ?? null;

        if (!is_string($password) || $password === '') {
            return false;
        }

        // Verify against a throwaway hash when no user was found, so a login
        // attempt costs the same whether or not the account exists. Skipping
        // this makes valid usernames discoverable by response time alone.
        if ($user === null) {
            $this->hasher->check($password, $this->hasher->dummyHash());

            return false;
        }

        return $this->hasher->check($password, $user->getAuthPassword());
    }

    public function rehashPasswordIfRequired(Authenticatable $user, #[SensitiveParameter] array $credentials): void
    {
        $password = $credentials['password'] ?? null;

        if (!is_string($password) || !$this->hasher->needsRehash($user->getAuthPassword())) {
            return;
        }

        if (!$user instanceof Model) {
            return;
        }

        // Raising the cost reaches existing users here, at their next login,
        // without anyone having to reset a password.
        $user->forceFill(['password' => $this->hasher->make($password)])->save();
    }
}
