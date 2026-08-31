<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\UserService;
use Kayra\Foundation\ServiceProvider;

/**
 * Application wiring.
 *
 * register() may only add bindings — nothing is guaranteed to be resolvable
 * yet. Anything that needs another service belongs in boot().
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: one instance per request, discarded when the request ends.
        // This is the lifetime to reach for by default when a service holds any
        // per-request state, because it stays correct under Swoole.
        $this->app->scoped(UserService::class);
    }

    public function boot(): void
    {
        // Every service is registered by the time this runs, so resolving is safe.
    }
}
