<?php

declare(strict_types=1);

namespace Kayra\Auth;

use Kayra\Config\Repository;
use Kayra\Container\Container;
use Kayra\Session\Session;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Resolves guards by name.
 *
 * Guards are request-scoped, not singletons: a session guard holds the current
 * user, and caching that across requests in a long-running worker would serve
 * one user's identity to the next request. The container's scoped bucket
 * enforces the lifetime; this class only builds them.
 */
final class AuthManager
{
    /** @var array<string, Guard> */
    private array $guards = [];

    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {
    }

    public function guard(?string $name = null): Guard
    {
        $name ??= $this->config->string('auth.default', 'web');

        return $this->guards[$name] ??= $this->resolve($name);
    }

    /**
     * The session guard, for login/logout.
     */
    public function session(?string $name = null): SessionGuard
    {
        $guard = $this->guard($name);

        if (!$guard instanceof SessionGuard) {
            throw new RuntimeException(
                'Guard [' . ($name ?? 'default') . '] is not a session guard, so it cannot log users in.',
            );
        }

        return $guard;
    }

    public function user(?string $guard = null): ?Authenticatable
    {
        return $this->guard($guard)->user();
    }

    public function check(?string $guard = null): bool
    {
        return $this->guard($guard)->check();
    }

    public function id(?string $guard = null): int|string|null
    {
        return $this->guard($guard)->id();
    }

    /**
     * Forget resolved guards. Called between requests.
     */
    public function flush(): void
    {
        $this->guards = [];
    }

    private function resolve(string $name): Guard
    {
        /** @var array<string, mixed>|null $config */
        $config = $this->config->get("auth.guards.{$name}");

        if (!is_array($config)) {
            throw new RuntimeException("Auth guard [{$name}] is not configured.");
        }

        $provider = $this->provider((string) ($config['provider'] ?? ''));

        return match ((string) ($config['driver'] ?? '')) {
            'session' => new SessionGuard($provider, $this->container->get(Session::class)),
            'token'   => new TokenGuard(
                $provider,
                $this->container->get(ServerRequestInterface::class),
                (string) ($config['token_model'] ?? ''),
                (string) ($config['user_key'] ?? 'user_id'),
                (string) ($config['hash_column'] ?? 'token'),
            ),
            default => throw new RuntimeException(
                "Auth guard [{$name}] has no usable driver; expected 'session' or 'token'.",
            ),
        };
    }

    private function provider(string $name): UserProvider
    {
        /** @var array<string, mixed>|null $config */
        $config = $this->config->get("auth.providers.{$name}");

        if (!is_array($config)) {
            throw new RuntimeException("Auth provider [{$name}] is not configured.");
        }

        return match ((string) ($config['driver'] ?? '')) {
            'model' => new ModelUserProvider(
                (string) ($config['model'] ?? ''),
                $this->container->get(Hasher::class),
            ),
            default => throw new RuntimeException(
                "Auth provider [{$name}] has no usable driver; expected 'model'.",
            ),
        };
    }
}
