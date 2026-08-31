<?php

declare(strict_types=1);

namespace Kayra\Routing;

use Closure;
use LogicException;

/**
 * Holds every registered route, plus the group stack used while loading them.
 */
final class RouteCollection
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    /**
     * Stack of active group attributes.
     *
     * @var list<array{prefix: string, middleware: list<string>, name: string, domain: ?string}>
     */
    private array $groupStack = [];

    /**
     * Load route files, binding the static {@see Route} DSL for their duration.
     *
     * The binding is always released, even if a route file throws, so a failed
     * boot cannot leave the DSL pointing at a stale collection.
     *
     * @param list<string> $files
     */
    public function load(array $files): void
    {
        Route::setCollector($this);

        try {
            foreach ($files as $file) {
                if (is_file($file)) {
                    (static function (string $__file): void {
                        require $__file;
                    })($file);
                }
            }
        } finally {
            Route::setCollector(null);
        }
    }

    /**
     * Register a route, applying the current group attributes.
     *
     * @param list<string> $methods
     */
    public function add(array $methods, string $uri, mixed $handler): Route
    {
        $methods = array_values(array_unique(array_map(strtoupper(...), $methods)));

        // A GET route always answers HEAD too, per RFC 9110.
        if (in_array('GET', $methods, true) && !in_array('HEAD', $methods, true)) {
            $methods[] = 'HEAD';
        }

        $route = new Route($methods, $this->prefixed($uri), $handler);

        foreach ($this->groupStack as $group) {
            if ($group['middleware'] !== []) {
                $route->middleware($group['middleware']);
            }

            if ($group['name'] !== '') {
                $route->name($group['name']);
            }

            if ($group['domain'] !== null) {
                $route->domain($group['domain']);
            }
        }

        $this->routes[] = $route;

        return $route;
    }

    /**
     * @param array{prefix?: string, middleware?: string|list<string>, name?: string, domain?: string}|string $attributes
     */
    public function group(array|string $attributes, Closure $routes): void
    {
        if (is_string($attributes)) {
            $attributes = ['prefix' => $attributes];
        }

        $this->groupStack[] = [
            'prefix'     => trim($attributes['prefix'] ?? '', '/'),
            'middleware' => array_values((array) ($attributes['middleware'] ?? [])),
            'name'       => $attributes['name'] ?? '',
            'domain'     => $attributes['domain'] ?? null,
        ];

        try {
            $routes($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    /**
     * The seven conventional resource routes.
     *
     * @param array{only?: list<string>, except?: list<string>, names?: array<string,string>} $options
     */
    public function resource(string $uri, string $controller, array $options = []): void
    {
        $uri = '/' . trim($uri, '/');
        $parameter = $this->resourceParameter($uri);

        $actions = [
            'index'   => ['GET',    $uri],
            'create'  => ['GET',    $uri . '/create'],
            'store'   => ['POST',   $uri],
            'show'    => ['GET',    $uri . '/{' . $parameter . '}'],
            'edit'    => ['GET',    $uri . '/{' . $parameter . '}/edit'],
            'update'  => ['PUT',    $uri . '/{' . $parameter . '}'],
            'destroy' => ['DELETE', $uri . '/{' . $parameter . '}'],
        ];

        $only = $options['only'] ?? array_keys($actions);
        $except = $options['except'] ?? [];
        $base = trim(str_replace('/', '.', trim($uri, '/')), '.');

        foreach ($actions as $action => [$method, $path]) {
            if (!in_array($action, $only, true) || in_array($action, $except, true)) {
                continue;
            }

            $methods = $action === 'update' ? ['PUT', 'PATCH'] : [$method];

            $this->add($methods, $path, [$controller, $action])
                ->name($base . '.' . $action);
        }
    }

    /**
     * Singularise the last URI segment for the resource parameter name.
     */
    private function resourceParameter(string $uri): string
    {
        $segment = str_replace('-', '_', basename($uri));

        return match (true) {
            str_ends_with($segment, 'ies') => substr($segment, 0, -3) . 'y',
            str_ends_with($segment, 'sses'),
            str_ends_with($segment, 'shes'),
            str_ends_with($segment, 'ches') => substr($segment, 0, -2),
            str_ends_with($segment, 's') && !str_ends_with($segment, 'ss') => substr($segment, 0, -1),
            default => $segment,
        };
    }

    private function prefixed(string $uri): string
    {
        $segments = [];

        foreach ($this->groupStack as $group) {
            if ($group['prefix'] !== '') {
                $segments[] = $group['prefix'];
            }
        }

        $segments[] = trim($uri, '/');

        $path = '/' . implode('/', array_filter($segments, static fn (string $s): bool => $s !== ''));

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * Finalise the collection: build the name index and check for duplicates.
     */
    public function index(): void
    {
        $this->named = [];

        foreach ($this->routes as $route) {
            $name = $route->getName();

            if ($name === null || $name === '') {
                continue;
            }

            if (isset($this->named[$name])) {
                throw new LogicException(
                    "Duplicate route name [{$name}]: "
                    . "{$this->named[$name]->uri} and {$route->uri} both use it.",
                );
            }

            $this->named[$name] = $route;
        }
    }

    /**
     * @return list<Route>
     */
    public function all(): array
    {
        return $this->routes;
    }

    public function getByName(string $name): ?Route
    {
        return $this->named[$name] ?? null;
    }

    /**
     * @return array<string, Route>
     */
    public function named(): array
    {
        return $this->named;
    }

    public function count(): int
    {
        return count($this->routes);
    }
}
