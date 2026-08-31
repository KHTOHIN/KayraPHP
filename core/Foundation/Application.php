<?php

declare(strict_types=1);

namespace Kayra\Foundation;

use Kayra\Config\Repository;
use Kayra\Container\Container;
use Kayra\Env\Env;
use RuntimeException;

/**
 * The application: a container that knows where things live and how to boot.
 *
 * Boot happens in two passes. `register()` on every provider runs first, so all
 * bindings exist before any `boot()` method runs and providers never have to
 * care about registration order.
 */
final class Application extends Container
{
    public const VERSION = '0.1.0';

    /** @var list<ServiceProvider> */
    private array $providers = [];

    /** @var array<class-string, ServiceProvider> */
    private array $registered = [];

    private bool $booted = false;

    private bool $bootstrapped = false;

    private ?PackageManifest $packages = null;

    private readonly string $basePath;


    public function __construct(string $basePath)
    {
        parent::__construct();

        $this->basePath = rtrim(str_replace('\\', '/', $basePath), '/');

        $this->instance(self::class, $this);
        $this->alias('app', self::class);
        $this->alias(Container::class, self::class);
    }

    /* --------------------------------------------------------------------
     | Bootstrapping
     * -------------------------------------------------------------------- */

    /**
     * Load environment and configuration, then register providers.
     *
     * Safe to call more than once; only the first call does work.
     */
    public function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        $this->bootstrapped = true;

        $this->loadEnvironment();
        $this->loadConfiguration();
        $this->loadCompiledServices();
        $this->registerConfiguredProviders();
    }

    /**
     * Install the compiled service graph, when one has been built.
     *
     * Loaded before providers register so that everything they resolve during
     * boot already uses the reflection-free path.
     */
    private function loadCompiledServices(): void
    {
        $cached = $this->bootstrapCachePath('services.php');

        if (is_file($cached)) {
            /** @var array<class-string, list<array<string, mixed>>> $plan */
            $plan = require $cached;

            $this->useCompiled($plan);
        }
    }

    private function loadEnvironment(): void
    {
        Env::loadFromProcess();
        Env::load($this->basePath . '/.env');
    }

    private function loadConfiguration(): void
    {
        $cached = $this->bootstrapCachePath('config.php');

        $config = is_file($cached)
            ? new Repository(require $cached)
            : Repository::fromDirectory($this->configPath());

        $this->instance(Repository::class, $config);
        $this->alias('config', Repository::class);

        date_default_timezone_set($config->string('app.timezone', 'UTC'));
        mb_internal_encoding('UTF-8');
    }

    private function registerConfiguredProviders(): void
    {
        /** @var list<class-string<ServiceProvider>> $providers */
        $providers = $this->config()->array('app.providers', []);

        // Packages register after the framework and before the application, so
        // a package can build on the framework and the application can override
        // anything a package set up.
        $discovered = $this->packages()->providers();

        foreach ([...$providers, ...$discovered] as $provider) {
            if (is_string($provider) && class_exists($provider)) {
                $this->register($provider);
            }
        }
    }

    /**
     * Service providers advertised by installed Composer packages.
     */
    public function packages(): PackageManifest
    {
        return $this->packages ??= new PackageManifest(
            $this->basePath('vendor'),
            $this->bootstrapCachePath('packages.php'),
            array_values(array_filter(
                $this->config()->array('app.dont_discover', []),
                is_string(...),
            )),
        );
    }

    /**
     * Register a service provider.
     *
     * @param ServiceProvider|class-string<ServiceProvider> $provider
     */
    public function register(ServiceProvider|string $provider): ServiceProvider
    {
        $class = is_string($provider) ? $provider : $provider::class;

        if (isset($this->registered[$class])) {
            return $this->registered[$class];
        }

        if (is_string($provider)) {
            if (!class_exists($provider)) {
                throw new RuntimeException("Service provider [{$provider}] does not exist.");
            }

            $provider = new $provider($this);
        }

        if (!$provider instanceof ServiceProvider) {
            throw new RuntimeException(
                "[{$class}] must extend " . ServiceProvider::class . '.',
            );
        }

        $this->registered[$class] = $provider;
        $this->providers[] = $provider;

        $provider->register();

        // A provider added after boot must be booted immediately.
        if ($this->booted) {
            $provider->boot();
        }

        return $provider;
    }

    /**
     * Run the boot pass over every registered provider.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->bootstrap();

        foreach ($this->providers as $provider) {
            $provider->boot();
        }

        $this->booted = true;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * @return list<ServiceProvider>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    /* --------------------------------------------------------------------
     | Environment
     * -------------------------------------------------------------------- */

    public function config(): Repository
    {
        return $this->get(Repository::class);
    }

    public function environment(): string
    {
        return $this->config()->string('app.env', 'production');
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }

    public function isLocal(): bool
    {
        return in_array($this->environment(), ['local', 'development', 'dev'], true);
    }

    public function isTesting(): bool
    {
        return in_array($this->environment(), ['testing', 'test'], true);
    }

    /**
     * Whether detailed errors may be shown.
     *
     * Debug is force-disabled in production regardless of configuration: an
     * APP_DEBUG=true that reaches production is a data leak, not a preference.
     */
    public function isDebug(): bool
    {
        return !$this->isProduction() && $this->config()->bool('app.debug', false);
    }

    public function runningInConsole(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    /* --------------------------------------------------------------------
     | Paths
     * -------------------------------------------------------------------- */

    public function basePath(string $path = ''): string
    {
        return $this->join($this->basePath, $path);
    }

    public function appPath(string $path = ''): string
    {
        return $this->join($this->basePath . '/app', $path);
    }

    public function configPath(string $path = ''): string
    {
        return $this->join($this->basePath . '/config', $path);
    }

    public function routesPath(string $path = ''): string
    {
        return $this->join($this->basePath . '/routes', $path);
    }

    public function storagePath(string $path = ''): string
    {
        return $this->join($this->basePath . '/storage', $path);
    }

    public function databasePath(string $path = ''): string
    {
        return $this->join($this->basePath . '/database', $path);
    }

    public function publicPath(string $path = ''): string
    {
        return $this->join($this->basePath . '/public', $path);
    }

    public function resourcePath(string $path = ''): string
    {
        return $this->join($this->basePath . '/resources', $path);
    }

    /**
     * Path inside bootstrap/cache, where compiled artefacts live.
     */
    public function bootstrapCachePath(string $path = ''): string
    {
        return $this->join($this->basePath . '/bootstrap/cache', $path);
    }

    private function join(string $base, string $path): string
    {
        return $path === '' ? $base : $base . '/' . ltrim($path, '/\\');
    }

    /**
     * Create a storage directory if it does not exist yet.
     */
    public function ensureDirectory(string $path): string
    {
        if (!is_dir($path) && !@mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new RuntimeException("Unable to create directory [{$path}].");
        }

        return $path;
    }

    /* --------------------------------------------------------------------
     | Optimisation state
     * -------------------------------------------------------------------- */

    public function configIsCached(): bool
    {
        return is_file($this->bootstrapCachePath('config.php'));
    }

    public function routesAreCached(): bool
    {
        return is_file($this->bootstrapCachePath('routes.php'));
    }

    public function servicesAreCached(): bool
    {
        return is_file($this->bootstrapCachePath('services.php'));
    }

    /* --------------------------------------------------------------------
     | Request lifecycle
     * -------------------------------------------------------------------- */

    /**
     * Release per-request state.
     *
     * Long-running runtimes must call this after every request. It is the single
     * point that decides what does and does not survive between requests.
     */
    public function terminate(): void
    {
        $this->forgetScoped();
    }
}
