<?php

declare(strict_types=1);

namespace Kayra\Foundation;

use Kayra\Config\Repository;
use Kayra\Container\ContainerCompiler;
use Kayra\Routing\Router;
use Kayra\View\Factory as ViewFactory;
use RuntimeException;

/**
 * Builds and removes the compiled artefacts used in production.
 *
 * "Compiling" here means precomputation, not native compilation: PHP still
 * interprets the result. What it removes is the repeated work — globbing the
 * config directory, parsing route files into regular expressions, and checking
 * whether each template is stale — that would otherwise happen on every
 * single request.
 */
final class Optimizer
{
    public function __construct(private readonly Application $app)
    {
    }

    /**
     * Flatten every config file into one array literal.
     *
     * Removes a directory glob plus one `require` per config file from boot.
     */
    public function cacheConfig(): string
    {
        // Read from disk rather than from the container: the in-memory copy may
        // have been mutated at run time, and the cache must reflect the files.
        $config = Repository::fromDirectory($this->app->configPath());

        $target = $this->cachePath('config.php');

        $this->write($target, $config->all(), 'Compiled configuration');

        return $target;
    }

    /**
     * Precompute the route dispatch table.
     *
     * The route files are still loaded at boot, because a closure handler
     * cannot be serialised. What is cached is the FastRoute compilation step,
     * which is the expensive half.
     */
    public function cacheRoutes(): string
    {
        /** @var list<string> $files */
        $files = $this->app->config()->array('app.route_files', [
            $this->app->routesPath('web.php'),
            $this->app->routesPath('api.php'),
        ]);

        $router = new Router();
        $router->load($files);

        $target = $this->cachePath('routes.php');

        $dispatch = $router->compile();

        $this->write($target, [
            'routes'      => array_values($files),
            'fingerprint' => self::routeFingerprint($files),
            // Second, independent guard: even if the fingerprint were skipped,
            // a differing route count rejects the cache.
            'count'       => $router->routes()->count(),
            'dispatch'    => $dispatch,
        ], 'Compiled route table');

        return $target;
    }

    /**
     * A content hash of the route files.
     *
     * The dispatch table maps to routes by *position*, so a route file that
     * gained or lost a route while a cache was live would silently dispatch
     * requests to the wrong handler. Comparing this fingerprint at boot turns
     * that class of bug into an automatic recompile.
     *
     * @param list<string> $files
     */
    public static function routeFingerprint(array $files): string
    {
        $parts = [];

        foreach ($files as $file) {
            $parts[] = is_file($file)
                ? $file . ':' . hash_file('xxh128', $file)
                : $file . ':missing';
        }

        return hash('xxh128', implode('|', $parts));
    }

    /**
     * Compile every template ahead of time.
     *
     * @return int Number of templates compiled.
     */
    public function cacheViews(): int
    {
        return $this->app->get(ViewFactory::class)->compileAll();
    }

    /**
     * Flatten the service graph into a construction plan.
     *
     * This is the step that removes reflection from the request path: every
     * controller, middleware, service provider binding and their transitive
     * dependencies get their constructor signatures resolved once, here.
     *
     * @return array{path: string, compiled: int, skipped: list<string>}
     */
    public function cacheServices(): array
    {
        $compiler = new ContainerCompiler($this->app);
        $plan = $compiler->compile($this->discoverRoots());

        $target = $this->cachePath('services.php');

        $this->write($target, $plan, 'Compiled service graph');

        return [
            'path'     => $target,
            'compiled' => count($plan),
            'skipped'  => $compiler->skipped(),
        ];
    }

    /**
     * Rebuild the installed-package manifest.
     *
     * @return int Packages that advertise KayraPHP integration.
     */
    public function cachePackages(): int
    {
        return count($this->app->packages()->rebuild());
    }

    /**
     * Emit an opcache.preload script.
     *
     * OPcache normally compiles a file the first time a worker touches it.
     * Preloading compiles the framework once at server start and keeps it in
     * shared memory for the lifetime of the process, so no request pays for
     * compilation and no `require` hits the filesystem.
     *
     * Only framework classes are preloaded. Application classes are excluded on
     * purpose: preloaded files cannot be changed without restarting the server,
     * which would make development miserable for no gain.
     *
     * @return array{path: string, files: int}
     */
    public function cachePreload(): array
    {
        $target = $this->cachePath('preload.php');
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->app->basePath('core'),
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            // helpers.php defines functions and is loaded by the autoloader;
            // preloading it as well would redeclare them.
            if (str_ends_with($path, '/Support/helpers.php')) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        $list = implode("\n", array_map(
            static fn (string $f): string => "    '" . str_replace("'", "\\'", $f) . "',",
            $files,
        ));

        $contents = <<<PHP
            <?php

            /*
             * Generated by `kayra optimize`. Do not edit.
             *
             * Point OPcache at this file to compile the framework once at server
             * start instead of once per worker:
             *
             *     opcache.preload=/path/to/bootstrap/cache/preload.php
             *     opcache.preload_user=www-data
             *
             * Only framework code is listed. Application classes are deliberately
             * left out so they can be changed without restarting the server.
             */

            if (!function_exists('opcache_compile_file') || !ini_get('opcache.enable')) {
                return;
            }

            require_once __DIR__ . '/../../vendor/autoload.php';

            \$files = [
            {$list}
            ];

            foreach (\$files as \$file) {
                // A class whose parent is not yet compiled simply warms later;
                // failing the whole preload over one file is not worth it.
                @opcache_compile_file(\$file);
            }

            PHP;

        if (file_put_contents($target, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write [{$target}].");
        }

        return ['path' => $target, 'files' => count($files)];
    }

    /**
     * Every class the application is known to resolve from the container.
     *
     * @return list<string>
     */
    private function discoverRoots(): array
    {
        $roots = [];

        // Anything with an explicit binding.
        foreach ($this->app->definitions() as $id => $definition) {
            $roots[] = $id;

            $concrete = $definition->concreteClass();

            if ($concrete !== null) {
                $roots[] = $concrete;
            }
        }

        $config = $this->app->config();

        // Global middleware, route-middleware aliases and console commands.
        foreach ($config->array('app.middleware', []) as $middleware) {
            $roots[] = (string) $middleware;
        }

        foreach ($config->array('app.middleware_aliases', []) as $alias) {
            foreach ((array) $alias as $middleware) {
                $roots[] = (string) $middleware;
            }
        }

        foreach ($config->array('app.commands', []) as $command) {
            $roots[] = (string) $command;
        }

        foreach ($config->array('app.providers', []) as $provider) {
            $roots[] = (string) $provider;
        }

        // Controllers named by routes.
        foreach ($this->app->get(Router::class)->routes()->all() as $route) {
            $handler = $route->handler;

            if (is_array($handler) && is_string($handler[0])) {
                $roots[] = $handler[0];
            } elseif (is_string($handler)) {
                $roots[] = str_contains($handler, '@') ? explode('@', $handler, 2)[0] : $handler;
            }

            foreach ($route->getMiddleware() as $middleware) {
                // Strip any `alias:params` suffix before resolving.
                $name = explode(':', (string) $middleware, 2)[0];
                $target = $config->get("app.middleware_aliases.{$name}", $name);

                foreach ((array) $target as $class) {
                    $roots[] = (string) $class;
                }
            }
        }

        return array_values(array_unique(array_filter($roots, class_exists(...))));
    }

    /**
     * Remove every compiled artefact.
     *
     * @return array<string, int> Artefact name => files removed.
     */
    public function clear(): array
    {
        $removed = [
            'config'   => (int) $this->delete($this->cachePath('config.php')),
            'routes'   => (int) $this->delete($this->cachePath('routes.php')),
            'services' => (int) $this->delete($this->cachePath('services.php')),
            'preload'  => (int) $this->delete($this->cachePath('preload.php')),
            'packages' => (int) $this->delete($this->cachePath('packages.php')),
            'views'    => 0,
        ];

        $viewCache = $this->app->config()->string(
            'view.cache',
            $this->app->storagePath('framework/views'),
        );

        foreach (glob(rtrim($viewCache, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $removed['views'] += (int) $this->delete($file);
        }

        return $removed;
    }

    private function delete(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }

        return @unlink($path);
    }

    private function cachePath(string $file): string
    {
        return $this->app->ensureDirectory($this->app->bootstrapCachePath()) . '/' . $file;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function write(string $path, array $data, string $label): void
    {
        $exported = var_export($data, true);

        // A closure in a config file cannot be exported; fail loudly rather
        // than writing a cache file that silently loses it.
        if (str_contains($exported, '\\Closure::__set_state')) {
            throw new RuntimeException(
                "Cannot cache [{$path}]: it contains a closure. Replace it with a class name or a plain value.",
            );
        }

        $contents = "<?php\n\n/* {$label} by KayraPHP. Do not edit; run `kayra optimize` instead. */\n\nreturn {$exported};\n";

        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write [{$path}].");
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
    }
}
