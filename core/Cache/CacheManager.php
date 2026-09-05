<?php

declare(strict_types=1);

namespace Kayra\Cache;

use Closure;
use Kayra\Config\Repository as Config;
use Kayra\Encryption\Encrypter;
use Kayra\Foundation\Application;
use RuntimeException;

/**
 * Resolves named cache stores from configuration.
 *
 * Stores are built once and shared. That is safe because a store holds no
 * request state -- the array driver holds cached values, which is the point,
 * and a request that must not see another's cached value should not be sharing
 * a cache key with it.
 */
final class CacheManager
{
    /** @var array<string, Repository> */
    private array $stores = [];

    /** @var array<string, Closure(array<string, mixed>): Store> */
    private array $custom = [];

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
    ) {
    }

    /**
     * A configured store, or the default one.
     */
    public function store(?string $name = null): Repository
    {
        $name ??= $this->config->string('cache.default', 'file');

        return $this->stores[$name] ??= $this->resolve($name);
    }

    /**
     * Register a driver the framework does not ship.
     *
     * @param Closure(array<string, mixed>): Store $factory
     */
    public function extend(string $driver, Closure $factory): void
    {
        $this->custom[$driver] = $factory;

        // A store already built from the old definition would keep serving.
        $this->stores = [];
    }

    private function resolve(string $name): Repository
    {
        $settings = $this->config->array("cache.stores.{$name}", []);

        if ($settings === []) {
            throw new RuntimeException(
                "Cache store [{$name}] is not configured. Add it under cache.stores in config/cache.php.",
            );
        }

        $driver = is_string($settings['driver'] ?? null) ? $settings['driver'] : '';

        $store = match ($driver) {
            'array' => new ArrayStore(),
            'file'  => new FileStore(
                is_string($settings['path'] ?? null)
                    ? $settings['path']
                    : $this->app->storagePath('framework/cache'),
                // Signed at rest when the application has a key, so a cache
                // file edited on disk is a miss rather than an unserialize().
                $this->app->bound(Encrypter::class) ? $this->app->get(Encrypter::class) : null,
            ),
            default => isset($this->custom[$driver])
                ? ($this->custom[$driver])($settings)
                : throw new RuntimeException(
                    "Cache store [{$name}] asks for driver [{$driver}], which is not registered. "
                    . "Built in: array, file. Add your own with CacheManager::extend().",
                ),
        };

        $ttl = $settings['ttl'] ?? null;

        return new Repository(
            $store,
            is_int($ttl) || (is_string($ttl) && ctype_digit($ttl))
                ? (int) $ttl
                : $this->config->int('cache.ttl', 3600),
        );
    }
}
