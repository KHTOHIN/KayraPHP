<?php

declare(strict_types=1);

namespace Kayra\Foundation\Providers;

use Kayra\Cache\CacheManager;
use Kayra\Cache\Repository;
use Kayra\Foundation\Application;
use Kayra\Foundation\ServiceProvider;
use Psr\SimpleCache\CacheInterface;

/**
 * Registers the cache.
 */
final class CacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            CacheManager::class,
            static fn (Application $app): CacheManager => new CacheManager($app, $app->config()),
        );

        // The default store, resolved lazily: an application that never caches
        // never touches the filesystem to find out where its cache would go.
        $this->app->singleton(
            Repository::class,
            static fn (Application $app): Repository => $app->get(CacheManager::class)->store(),
            lazy: true,
        );

        $this->app->alias(CacheInterface::class, Repository::class);
        $this->app->alias('cache', Repository::class);
    }
}
