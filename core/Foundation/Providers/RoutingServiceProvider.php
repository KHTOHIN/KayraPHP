<?php

declare(strict_types=1);

namespace Kayra\Foundation\Providers;

use Kayra\Encryption\Encrypter;
use Kayra\Exceptions\Handler as ExceptionHandler;
use Kayra\Foundation\Application;
use Kayra\Foundation\Optimizer;
use Kayra\Foundation\ServiceProvider;
use Kayra\Http\Kernel;
use Kayra\Routing\Router;
use Kayra\Routing\UrlGenerator;

/**
 * Registers the router, URL generator and HTTP kernel.
 */
final class RoutingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Router::class, static function (Application $app): Router {
            $router = new Router();

            $files = $app->config()->array('app.route_files', [
                $app->routesPath('web.php'),
                $app->routesPath('api.php'),
            ]);

            // A cached route table skips the FastRoute compilation step.
            $cache = $app->bootstrapCachePath('routes.php');

            if (is_file($cache)) {
                /** @var array{routes: list<string>, fingerprint?: string, count?: int, dispatch: array<int, mixed>} $cached */
                $cached = require $cache;

                $router->load($cached['routes']);

                // The dispatch table addresses routes by position. If the route
                // files changed since the cache was built, those positions no
                // longer line up and the cache would dispatch to the wrong
                // handler — so verify before trusting it.
                $fresh = ($cached['fingerprint'] ?? null) === Optimizer::routeFingerprint($cached['routes']);

                if ($fresh) {
                    $router->useCompiled($cached['dispatch'], $cached['count'] ?? null);
                } else {
                    $router->compile();
                }

                return $router;
            }

            $router->load($files);
            $router->compile();

            return $router;
        });

        $this->app->singleton(UrlGenerator::class, static function (Application $app): UrlGenerator {
            return new UrlGenerator(
                $app->get(Router::class)->routes(),
                $app->config()->string('app.url', 'http://localhost'),
                // Lazy, so a project without an APP_KEY still boots; it only
                // fails if a signed URL is actually requested.
                $app->get(Encrypter::class),
            );
        });

        $this->app->singleton(Kernel::class, static function (Application $app): Kernel {
            $config = $app->config();

            return new Kernel(
                $app,
                $app->get(Router::class),
                $app->get(ExceptionHandler::class),
                $config->array('app.middleware', []),
                $config->array('app.middleware_aliases', []),
            );
        });
    }
}
