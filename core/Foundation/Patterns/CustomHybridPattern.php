<?php

namespace Kayra\Foundation\Patterns;

use Kayra\Foundation\Application;
use Kayra\Http\Request;
use Kayra\Http\Response;
use Kayra\Http\Router;

/**
 * Custom Hybrid architecture pattern implementation
 * 
 * This pattern allows developers to mix and match elements from different architecture patterns:
 * - Combines aspects of MVC, Factory-Service, and Domain-Driven patterns
 * - Provides flexibility to organize code in a way that best fits the project's needs
 * - Allows for custom directory structures and naming conventions
 */
class CustomHybridPattern implements ArchitecturePattern
{
    /**
     * Initialize the Custom Hybrid pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function initialize(Application $app): void
    {
        // Register core components
        $app->singleton(Router::class, fn() => new Router($app));
    }
    
    /**
     * Configure routing for the Custom Hybrid pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function configureRouting(Application $app): void
    {
        // Custom Hybrid uses a flexible routing system
        // It can load routes from multiple directories
        $router = $app->make(Router::class);
        
        // Load all route files from the routes directory and its subdirectories
        $routeDir = $app->basePath() . '/routes';
        $routeFiles = array_merge(
            glob($routeDir . '/*.php'),
            glob($routeDir . '/*/*.php')
        );
        
        if (!empty($routeFiles)) {
            $router->loadRoutes($routeFiles);
        }
    }
    
    /**
     * Configure module loading for the Custom Hybrid pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function configureModuleLoading(Application $app): void
    {
        // Custom Hybrid supports multiple module organization styles
        // Create all necessary directories
        $directories = [
            $app->basePath() . '/app/Controllers',
            $app->basePath() . '/app/Models',
            $app->basePath() . '/app/Services',
            $app->basePath() . '/app/Factories',
            $app->basePath() . '/app/Domains',
            $app->basePath() . '/app/Components',
        ];
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    /**
     * Configure autoload namespaces for the Custom Hybrid pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function configureAutoloadNamespaces(Application $app): void
    {
        // Custom Hybrid uses multiple namespaces
        // App\Controllers, App\Models, App\Services, App\Factories, App\Domains, App\Components
        // No special configuration needed as these are standard PSR-4 namespaces
    }
    
    /**
     * Handle a request using the Custom Hybrid pattern
     * 
     * @param Request $request The request to handle
     * @param Application $app The application instance
     * @return Response The response
     */
    public function handleRequest(Request $request, Application $app): Response
    {
        // Get the router
        $router = $app->make(Router::class);
        
        // Dispatch the request to the appropriate handler
        return $router->dispatch($request);
    }
}