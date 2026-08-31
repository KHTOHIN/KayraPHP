<?php

declare(strict_types=1);

/**
 * Global helper functions.
 *
 * These are conveniences for application code. Framework internals always use
 * injected dependencies instead, so the framework itself stays testable and
 * free of global state.
 */

use Kayra\Config\Repository;
use Kayra\Container\Container;
use Kayra\Env\Env;
use Kayra\Foundation\Application;
use Kayra\Http\Request;
use Kayra\Http\Response;
use Kayra\Routing\UrlGenerator;
use Kayra\View\Factory as ViewFactory;

if (!function_exists('app')) {
    /**
     * Resolve a service, or the application itself when called with no argument.
     *
     * @template T of object
     * @param class-string<T>|string|null $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    function app(?string $id = null): mixed
    {
        $app = Container::getInstance()
            ?? throw new RuntimeException('The application has not been bootstrapped yet.');

        return $id === null ? $app : $app->get($id);
    }
}

if (!function_exists('config')) {
    /**
     * Read configuration, or the repository itself when called with no argument.
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        $config = app(Repository::class);

        return $key === null ? $config : $config->get($key, $default);
    }
}

if (!function_exists('env')) {
    /**
     * Read an environment variable.
     *
     * Prefer config() in application code: once configuration is cached, .env is
     * no longer read at run time, so env() outside config files returns null in
     * production.
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return app(Application::class)->basePath($path);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return app(Application::class)->storagePath($path);
    }
}

if (!function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return app(Application::class)->configPath($path);
    }
}

if (!function_exists('database_path')) {
    function database_path(string $path = ''): string
    {
        return app(Application::class)->databasePath($path);
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return app(Application::class)->publicPath($path);
    }
}

if (!function_exists('resource_path')) {
    function resource_path(string $path = ''): string
    {
        return app(Application::class)->resourcePath($path);
    }
}

if (!function_exists('request')) {
    /**
     * The current request.
     */
    function request(): Request
    {
        return app(Request::class);
    }
}

if (!function_exists('view')) {
    /**
     * Render a template into a response.
     *
     * @param array<string, mixed> $data
     */
    function view(string $view, array $data = [], int $status = 200): Response
    {
        return Response::html(app(ViewFactory::class)->render($view, $data), $status);
    }
}

if (!function_exists('json')) {
    function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $to, int $status = 302): Response
    {
        return Response::redirect($to, $status);
    }
}

if (!function_exists('route')) {
    /**
     * Build the URL for a named route.
     *
     * @param array<string, mixed> $parameters
     */
    function route(string $name, array $parameters = [], bool $absolute = false): string
    {
        return app(UrlGenerator::class)->route($name, $parameters, $absolute);
    }
}

if (!function_exists('url')) {
    function url(string $path = '', bool $absolute = false): string
    {
        return app(UrlGenerator::class)->to($path, $absolute);
    }
}

if (!function_exists('e')) {
    /**
     * Escape a value for HTML output.
     */
    function e(mixed $value): string
    {
        return Kayra\View\Compiler::e($value);
    }
}

if (!function_exists('is_dev')) {
    function is_dev(): bool
    {
        return app(Application::class)->isLocal();
    }
}

if (!function_exists('is_prod')) {
    function is_prod(): bool
    {
        return app(Application::class)->isProduction();
    }
}

if (!function_exists('is_test')) {
    function is_test(): bool
    {
        return app(Application::class)->isTesting();
    }
}

if (!function_exists('logger')) {
    /**
     * The application logger.
     */
    function logger(): Psr\Log\LoggerInterface
    {
        return app(Psr\Log\LoggerInterface::class);
    }
}

if (!function_exists('abort')) {
    /**
     * Abort the request with an HTTP status.
     *
     * @param array<string, string> $headers
     */
    function abort(int $status, string $message = '', array $headers = []): never
    {
        throw match ($status) {
            404     => new Kayra\Exceptions\NotFoundHttpException($message ?: 'Not Found'),
            default => new Kayra\Exceptions\HttpException($status, $message, $headers),
        };
    }
}

if (!function_exists('abort_if')) {
    /**
     * @param array<string, string> $headers
     */
    function abort_if(bool $condition, int $status, string $message = '', array $headers = []): void
    {
        if ($condition) {
            abort($status, $message, $headers);
        }
    }
}

if (!function_exists('abort_unless')) {
    /**
     * @param array<string, string> $headers
     */
    function abort_unless(bool $condition, int $status, string $message = '', array $headers = []): void
    {
        if (!$condition) {
            abort($status, $message, $headers);
        }
    }
}

if (!function_exists('dd')) {
    /**
     * Dump values and stop.
     *
     * Refuses to run in production, so a forgotten dd() cannot leak internals.
     */
    function dd(mixed ...$values): never
    {
        $container = Container::getInstance();

        if ($container?->has(Application::class) === true && $container->get(Application::class)->isProduction()) {
            throw new RuntimeException('dd() was called in production.');
        }

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }

        foreach ($values as $value) {
            var_dump($value);
        }

        exit(1);
    }
}
