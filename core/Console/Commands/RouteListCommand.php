<?php

namespace Kayra\Console\Commands;

use Kayra\Foundation\Application;

class RouteListCommand
{
    public static function run(Application $app, array $args = [])
    {
        $router = $app->make(\Kayra\Http\Router::class);
        $routes = $router->getRoutes();

        if (empty($routes)) {
            echo "⚠️  No routes have been registered.\n";
            return;
        }

        echo "📜 Registered Routes:\n";
        echo str_repeat('-', 60) . "\n";

        foreach ($routes as $route) {
            // Each route looks like: ['GET', '/path', 'Handler']
            [$method, $uri, $handler] = array_pad($route, 3, null);

            $method = $method ?? '—';
            $uri = $uri ?? '—';
            $handler = is_string($handler) ? $handler : 'Closure';

            echo str_pad($method, 8) . " " . str_pad($uri, 30) . " →  {$handler}\n";
        }

        echo str_repeat('-', 60) . "\n";
    }
}