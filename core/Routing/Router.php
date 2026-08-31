<?php

declare(strict_types=1);

namespace Kayra\Routing;

use FastRoute\DataGenerator\GroupCountBased as DataGenerator;
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased as GroupCountBasedDispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std as RouteParser;
use Kayra\Exceptions\MethodNotAllowedHttpException;
use Kayra\Exceptions\NotFoundHttpException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Matches requests against the route table.
 *
 * Matching is delegated to nikic/fast-route, which compiles routes into a small
 * number of combined regular expressions — the fastest well-tested approach in
 * PHP. The dispatch data is plain arrays, so it can be dumped to a cache file
 * and loaded with a single `require` in production.
 */
final class Router
{
    /** @var array<int, mixed>|null Raw FastRoute dispatch data. */
    private ?array $dispatchData = null;

    private ?Dispatcher $dispatcher = null;

    /**
     * Exact-match routes: "METHOD\0/path" => route offset.
     *
     * Most routes in a real application have no parameters. Answering those
     * from a hash lookup skips building the FastRoute dispatcher and running
     * the combined regular expressions entirely — an array lookup instead of a
     * preg_match over a merged pattern.
     *
     * @var array<string, int>|null
     */
    private ?array $staticRoutes = null;

    public function __construct(
        private readonly RouteCollection $routes = new RouteCollection(),
    ) {
    }

    public function routes(): RouteCollection
    {
        return $this->routes;
    }

    /* --------------------------------------------------------------------
     | Registration passthrough (for use outside route files)
     * -------------------------------------------------------------------- */

    public function get(string $uri, mixed $handler): Route
    {
        return $this->invalidate()->routes->add(['GET', 'HEAD'], $uri, $handler);
    }

    public function post(string $uri, mixed $handler): Route
    {
        return $this->invalidate()->routes->add(['POST'], $uri, $handler);
    }

    public function put(string $uri, mixed $handler): Route
    {
        return $this->invalidate()->routes->add(['PUT'], $uri, $handler);
    }

    public function patch(string $uri, mixed $handler): Route
    {
        return $this->invalidate()->routes->add(['PATCH'], $uri, $handler);
    }

    public function delete(string $uri, mixed $handler): Route
    {
        return $this->invalidate()->routes->add(['DELETE'], $uri, $handler);
    }

    public function options(string $uri, mixed $handler): Route
    {
        return $this->invalidate()->routes->add(['OPTIONS'], $uri, $handler);
    }

    /**
     * @param list<string> $methods
     */
    public function match(array $methods, string $uri, mixed $handler): Route
    {
        return $this->invalidate()->routes->add($methods, $uri, $handler);
    }

    public function group(array|string $attributes, \Closure $routes): void
    {
        $this->invalidate()->routes->group($attributes, $routes);
    }

    /**
     * @param list<string> $files
     */
    public function load(array $files): void
    {
        $this->invalidate()->routes->load($files);
    }

    private function invalidate(): self
    {
        $this->dispatchData = null;
        $this->dispatcher = null;
        $this->staticRoutes = null;

        return $this;
    }

    /**
     * Build the exact-match index.
     *
     * A route qualifies when its URI contains no placeholder and it carries no
     * domain constraint — anything else needs the full matcher.
     *
     * @return array<string, int>
     */
    private function staticRoutes(): array
    {
        if ($this->staticRoutes !== null) {
            return $this->staticRoutes;
        }

        $index = [];

        foreach ($this->routes->all() as $offset => $route) {
            if (str_contains($route->uri, '{') || $route->getDomain() !== null) {
                continue;
            }

            foreach ($route->methods as $method) {
                $index[$method . "\0" . $route->uri] = $offset;
            }
        }

        return $this->staticRoutes = $index;
    }

    /* --------------------------------------------------------------------
     | Compilation
     * -------------------------------------------------------------------- */

    /**
     * Build the FastRoute dispatch data.
     *
     * Handlers are stored as integer offsets into the route list rather than as
     * callables, which is what makes the result safe to var_export() into the
     * route cache even when some handlers are closures.
     *
     * @return array<int, mixed>
     */
    public function compile(): array
    {
        if ($this->dispatchData !== null) {
            return $this->dispatchData;
        }

        $this->routes->index();

        $collector = new RouteCollector(new RouteParser(), new DataGenerator());

        foreach ($this->routes->all() as $offset => $route) {
            $collector->addRoute($route->methods, $route->compiledUri(), $offset);
        }

        return $this->dispatchData = $collector->getData();
    }

    /**
     * Load pre-compiled dispatch data produced by `kayra route:cache`.
     *
     * The dispatch table addresses routes by their *position* in the collection.
     * If the route files changed since the table was built, those positions no
     * longer line up and requests would be dispatched to the wrong handler —
     * silently, and with no error to notice. Passing $expectedRoutes makes that
     * impossible: a mismatch discards the cache and recompiles.
     *
     * @param array<int, mixed> $data
     * @param int|null $expectedRoutes Route count at the time the cache was built.
     * @return bool False when the cache was rejected as stale.
     */
    public function useCompiled(array $data, ?int $expectedRoutes = null): bool
    {
        if ($expectedRoutes !== null && count($this->routes->all()) !== $expectedRoutes) {
            $this->compile();

            return false;
        }

        $this->dispatchData = $data;
        $this->dispatcher = new GroupCountBasedDispatcher($data);
        $this->staticRoutes = null;

        // The dispatch table is cached, but the name index is not: it is built
        // from the Route objects the route files just created. Without this,
        // route() would fail for every named route whenever routes are cached.
        $this->routes->index();

        return true;
    }

    private function dispatcher(): Dispatcher
    {
        return $this->dispatcher ??= new GroupCountBasedDispatcher($this->compile());
    }

    /* --------------------------------------------------------------------
     | Dispatch
     * -------------------------------------------------------------------- */

    /**
     * Match a request to a route.
     *
     * @throws NotFoundHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function resolve(ServerRequestInterface $request): MatchedRoute
    {
        // PSR-7 preserves the method exactly as sent; matching is case-insensitive.
        $method = strtoupper($request->getMethod());
        $path = $this->normalisePath($request->getUri()->getPath());

        // Exact match first: no regex, no dispatcher construction.
        $offset = $this->staticRoutes()[$method . "\0" . $path] ?? null;

        if ($offset !== null) {
            return $this->found($offset, [], $request);
        }

        $result = $this->dispatcher()->dispatch($method, $path);

        return match ($result[0]) {
            Dispatcher::FOUND => $this->found($result[1], $result[2], $request),
            Dispatcher::METHOD_NOT_ALLOWED => throw new MethodNotAllowedHttpException(
                array_values(array_unique($result[1])),
                "Method {$method} is not allowed for {$path}.",
            ),
            default => throw new NotFoundHttpException("No route matches {$method} {$path}."),
        };
    }

    /**
     * @param array<string, string> $parameters
     */
    private function found(int $offset, array $parameters, ServerRequestInterface $request): MatchedRoute
    {
        $route = $this->routes->all()[$offset] ?? throw new NotFoundHttpException(
            'Route cache is stale: run `kayra route:clear`.',
        );

        if ($route->getDomain() !== null && !$this->domainMatches($route->getDomain(), $request)) {
            throw new NotFoundHttpException('No route matches the requested host.');
        }

        return new MatchedRoute($route, [...$route->getDefaults(), ...$parameters]);
    }

    private function domainMatches(string $domain, ServerRequestInterface $request): bool
    {
        return strcasecmp($domain, $request->getUri()->getHost()) === 0;
    }

    /**
     * Normalise the path before matching.
     *
     * Percent-decoding happens here, once, so route parameters carry decoded
     * values while the URI object keeps its encoded form.
     */
    private function normalisePath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        $path = rawurldecode($path);

        // Collapse duplicate slashes so //users and /users cannot diverge.
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;

        return rtrim($path, '/') ?: '/';
    }
}
