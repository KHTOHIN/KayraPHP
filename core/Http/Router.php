<?php

namespace Kayra\Http;

use Kayra\Foundation\Application;

class Router
{
    protected Application $app;
    protected array $routes = [];

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Load route files
     * 
     * @param array|null $routeFiles Optional array of route file paths to load
     */
    public function loadRoutes(array $routeFiles = null): void
    {
        if ($routeFiles === null) {
            // Default route files
            $routeDir = $this->app->basePath() . '/routes';
            $routeFiles = [
                $routeDir . '/web.php',
                $routeDir . '/api.php',
            ];
        }

        foreach ($routeFiles as $path) {
            if (file_exists($path)) {
                $routes = require $path;
                $this->routes = array_merge_recursive($this->routes, $routes);
            }
        }
    }

    /**
     * Dispatch request to the correct handler.
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $routeList = $this->routes[$method] ?? [];

        foreach ($routeList as $route => $handler) {
            if ($route === $path) {
                return $this->runHandler($handler, $request);
            }
        }

        return (new Response())->withStatus(404)->withHeader('Content-Type', 'text/plain')
            ->withBody(new Stream(fopen('php://temp', 'r+')))
            ->json(['error' => 'Route not found']);
    }

    /**
     * Run a route handler (closure or controller@method)
     */
    protected function runHandler($handler, Request $request, array $vars = []): Response
    {
        // If it's a closure
        if (is_callable($handler)) {
            $result = $handler($request);
            return $this->normalizeResponse($result);
        }

        // If it's a controller@method
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler);

            if (!class_exists($class)) {
                throw new \Exception("Controller not found: $class");
            }

            $response = $this->app->make(Response::class);

            // ✅ FIX: pass Request + Response into Controller constructor
            $controller = new $class($request, $response);

            if (!method_exists($controller, $method)) {
                throw new \Exception("Controller method not found: {$class}@{$method}");
            }

            $result = $controller->{$method}($request);
            return $this->normalizeResponse($result);
        }

        throw new \Exception('Invalid route handler');
    }

    protected function normalizeResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result) || is_object($result)) {
            return (new Response())->json($result);
        }

        return (new Response())->create(200, ['Content-Type' => ['text/html']], (string) $result);
    }

    /**
     * Get all registered routes
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
