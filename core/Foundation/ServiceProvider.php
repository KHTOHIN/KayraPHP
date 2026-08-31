<?php

declare(strict_types=1);

namespace Kayra\Foundation;

use Kayra\Config\Repository;

/**
 * Base class for service providers — the unit of framework and package wiring.
 *
 * `register()` may only add bindings. `boot()` runs once every provider has
 * registered, so it is the only place where resolving other services is safe.
 */
abstract class ServiceProvider
{
    public function __construct(protected readonly Application $app)
    {
    }

    /**
     * Add bindings to the container. Do not resolve anything here.
     */
    public function register(): void
    {
    }

    /**
     * Run after every provider has registered.
     */
    public function boot(): void
    {
    }

    protected function config(): Repository
    {
        return $this->app->config();
    }

    /**
     * Merge package defaults under a configuration key without overriding
     * values the application has already set.
     */
    protected function mergeConfigFrom(string $path, string $key): void
    {
        if (!is_file($path)) {
            return;
        }

        $defaults = require $path;

        if (!is_array($defaults)) {
            return;
        }

        $config = $this->config();
        $existing = $config->get($key, []);

        $config->set($key, array_replace_recursive($defaults, is_array($existing) ? $existing : []));
    }

    /**
     * Register additional template directories.
     */
    protected function loadViewsFrom(string $path): void
    {
        if (is_dir($path)) {
            $this->app->get(\Kayra\View\Factory::class)->addPath($path);
        }
    }
}
