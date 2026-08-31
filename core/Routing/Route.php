<?php

declare(strict_types=1);

namespace Kayra\Routing;

use Closure;
use LogicException;

/**
 * A single route.
 *
 * This class is also the registration DSL: the static methods (`Route::get()`,
 * `Route::group()`, ...) write into whichever {@see RouteCollection} is bound
 * while route files are being loaded.
 *
 * That binding exists only during boot — {@see RouteCollection::load()} sets it
 * and always clears it again — so no request-time global state is involved and
 * the DSL stays safe on long-running runtimes.
 */
final class Route
{
    /** @var list<string> */
    private array $middleware = [];

    private ?string $name = null;

    /** @var array<string, string> */
    private array $wheres = [];

    /** @var array<string, mixed> */
    private array $defaults = [];

    private ?string $domain = null;

    /** The collection currently receiving static registrations. */
    private static ?RouteCollection $collector = null;

    /**
     * @param list<string> $methods
     * @param string       $uri     Normalised path, always beginning with '/'.
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $uri,
        public readonly mixed $handler,
    ) {
    }

    /* --------------------------------------------------------------------
     | Static registration DSL
     * -------------------------------------------------------------------- */

    /**
     * @internal Bind the collection that static calls register into.
     */
    public static function setCollector(?RouteCollection $collection): void
    {
        self::$collector = $collection;
    }

    private static function collector(): RouteCollection
    {
        return self::$collector ?? throw new LogicException(
            'Route::' . 'get()/post()/... may only be called while route files are loading. '
            . 'Use $router->get(...) outside of a route file.',
        );
    }

    public static function get(string $uri, mixed $handler): self
    {
        return self::collector()->add(['GET', 'HEAD'], $uri, $handler);
    }

    public static function post(string $uri, mixed $handler): self
    {
        return self::collector()->add(['POST'], $uri, $handler);
    }

    public static function put(string $uri, mixed $handler): self
    {
        return self::collector()->add(['PUT'], $uri, $handler);
    }

    public static function patch(string $uri, mixed $handler): self
    {
        return self::collector()->add(['PATCH'], $uri, $handler);
    }

    public static function delete(string $uri, mixed $handler): self
    {
        return self::collector()->add(['DELETE'], $uri, $handler);
    }

    public static function options(string $uri, mixed $handler): self
    {
        return self::collector()->add(['OPTIONS'], $uri, $handler);
    }

    /**
     * Register a route responding to every standard method.
     */
    public static function any(string $uri, mixed $handler): self
    {
        return self::collector()->add(
            ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            $uri,
            $handler,
        );
    }

    /**
     * @param list<string> $methods
     */
    public static function match(array $methods, string $uri, mixed $handler): self
    {
        return self::collector()->add($methods, $uri, $handler);
    }

    /**
     * A route that only renders a view, with no controller.
     */
    public static function view(string $uri, string $view, array $data = []): self
    {
        return self::collector()->add(['GET', 'HEAD'], $uri, new ViewRoute($view, $data));
    }

    /**
     * A route that only redirects.
     */
    public static function redirect(string $uri, string $to, int $status = 302): self
    {
        return self::collector()->add(['GET', 'HEAD'], $uri, new RedirectRoute($to, $status));
    }

    /**
     * Group routes under shared attributes.
     *
     * @param array{prefix?: string, middleware?: string|list<string>, name?: string, domain?: string}|string $attributes
     *        A bare string is treated as a path prefix.
     */
    public static function group(array|string $attributes, Closure $routes): void
    {
        self::collector()->group($attributes, $routes);
    }

    /**
     * The seven conventional CRUD routes for a controller.
     *
     * @param array{only?: list<string>, except?: list<string>} $options
     */
    public static function resource(string $uri, string $controller, array $options = []): void
    {
        self::collector()->resource($uri, $controller, $options);
    }

    /**
     * Resource routes without the HTML form actions (create/edit) — for APIs.
     *
     * @param array{only?: list<string>, except?: list<string>} $options
     */
    public static function apiResource(string $uri, string $controller, array $options = []): void
    {
        $options['except'] = [...($options['except'] ?? []), 'create', 'edit'];

        self::collector()->resource($uri, $controller, $options);
    }

    /* --------------------------------------------------------------------
     | Fluent configuration
     * -------------------------------------------------------------------- */

    public function name(string $name): self
    {
        $this->name = ($this->name ?? '') . $name;

        return $this;
    }

    /**
     * @param string|list<string> $middleware
     */
    public function middleware(string|array $middleware): self
    {
        foreach ((array) $middleware as $item) {
            if (!in_array($item, $this->middleware, true)) {
                $this->middleware[] = $item;
            }
        }

        return $this;
    }

    /**
     * Constrain a route parameter with a regular expression.
     *
     * <code>->where('id', '\d+')</code>
     *
     * @param string|array<string, string> $name
     */
    public function where(string|array $name, ?string $pattern = null): self
    {
        foreach (is_array($name) ? $name : [$name => (string) $pattern] as $key => $regex) {
            $this->wheres[$key] = $regex;
        }

        return $this;
    }

    /** Shorthand for a numeric parameter. */
    public function whereNumber(string ...$names): self
    {
        foreach ($names as $name) {
            $this->wheres[$name] = '\d+';
        }

        return $this;
    }

    /** Shorthand for a slug parameter. */
    public function whereSlug(string ...$names): self
    {
        foreach ($names as $name) {
            $this->wheres[$name] = '[A-Za-z0-9-_]+';
        }

        return $this;
    }

    /** Shorthand for a UUID parameter. */
    public function whereUuid(string ...$names): self
    {
        foreach ($names as $name) {
            $this->wheres[$name] = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
        }

        return $this;
    }

    /**
     * Default values merged into the route parameters before dispatch.
     *
     * @param array<string, mixed> $defaults
     */
    public function defaults(array $defaults): self
    {
        $this->defaults = [...$this->defaults, ...$defaults];

        return $this;
    }

    public function domain(string $domain): self
    {
        $this->domain = $domain;

        return $this;
    }

    /* --------------------------------------------------------------------
     | Accessors
     * -------------------------------------------------------------------- */

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * @return array<string, string>
     */
    public function getWheres(): array
    {
        return $this->wheres;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaults(): array
    {
        return $this->defaults;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /**
     * The URI with `where` constraints inlined, in FastRoute syntax.
     *
     * `/users/{id}` with `where('id', '\d+')` becomes `/users/{id:\d+}`.
     */
    public function compiledUri(): string
    {
        if ($this->wheres === []) {
            return $this->uri;
        }

        return preg_replace_callback(
            '/\{(\w+)(\??)(?::([^{}]*(?:\{(?-1)\}[^{}]*)*))?\}/',
            function (array $m): string {
                $name = $m[1];
                $optional = $m[2];
                $pattern = $m[3] ?? '';

                // An inline pattern in the URI wins over a where() constraint.
                if ($pattern === '' && isset($this->wheres[$name])) {
                    $pattern = $this->wheres[$name];
                }

                return '{' . $name . ($pattern === '' ? '' : ':' . $pattern) . '}' . $optional;
            },
            $this->uri,
        ) ?? $this->uri;
    }

    /**
     * A stable, serialisable description used by the route cache.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'methods'    => $this->methods,
            'uri'        => $this->uri,
            'handler'    => $this->handler instanceof Closure ? null : $this->handler,
            'name'       => $this->name,
            'middleware' => $this->middleware,
            'wheres'     => $this->wheres,
            'defaults'   => $this->defaults,
            'domain'     => $this->domain,
        ];
    }
}
