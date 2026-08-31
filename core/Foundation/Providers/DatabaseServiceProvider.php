<?php

declare(strict_types=1);

namespace Kayra\Foundation\Providers;

use Kayra\Database\Connection;
use Kayra\Database\DatabaseManager;
use Kayra\Foundation\Application;
use Kayra\Foundation\ServiceProvider;

/**
 * Registers the database layer.
 */
final class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Connections are application-lifetime: they hold no request state, and
        // reconnecting per request is the most expensive thing a database layer
        // can do. Lazy, so an application that never queries never connects.
        $this->app->singleton(
            DatabaseManager::class,
            static fn (Application $app): DatabaseManager => new DatabaseManager($app->config()),
            lazy: true,
        );

        $this->app->singleton(
            Connection::class,
            static fn (Application $app): Connection => $app->get(DatabaseManager::class)->connection(),
            lazy: true,
        );

        $this->app->alias('db', DatabaseManager::class);
    }
}
