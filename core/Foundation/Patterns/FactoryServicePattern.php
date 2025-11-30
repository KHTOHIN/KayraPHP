<?php

namespace Kayra\Foundation\Patterns;

use Kayra\Foundation\Application;
use Kayra\Http\Request;
use Kayra\Http\Response;
use Kayra\Http\Router;

/**
 * Factory-Service architecture pattern implementation
 * 
 * This pattern separates the application into factories and services:
 * - Factories: Create and configure services
 * - Services: Contain the business logic
 * 
 * The pattern promotes dependency injection and separation of concerns.
 */
class FactoryServicePattern implements ArchitecturePattern
{
    /**
     * Initialize the Factory-Service pattern
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
     * Configure routing for the Factory-Service pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function configureRouting(Application $app): void
    {
        // Factory-Service uses service-based routing
        // Routes point to services instead of controllers
        $router = $app->make(Router::class);
        $router->loadRoutes();
    }
    
    /**
     * Configure module loading for the Factory-Service pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function configureModuleLoading(Application $app): void
    {
        // Factory-Service modules are organized in app/Factories and app/Services
        // Create directories if they don't exist
        $factoriesDir = $app->basePath() . '/app/Factories';
        $servicesDir = $app->basePath() . '/app/Services';
        
        if (!is_dir($factoriesDir)) {
            mkdir($factoriesDir, 0755, true);
        }
        
        if (!is_dir($servicesDir)) {
            mkdir($servicesDir, 0755, true);
        }
    }
    
    /**
     * Configure autoload namespaces for the Factory-Service pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function configureAutoloadNamespaces(Application $app): void
    {
        // Factory-Service uses App\Factories and App\Services namespaces
        // No special configuration needed as these are standard PSR-4 namespaces
    }
    
    /**
     * Handle a request using the Factory-Service pattern
     * 
     * @param Request $request The request to handle
     * @param Application $app The application instance
     * @return Response The response
     */
    public function handleRequest(Request $request, Application $app): Response
    {
        // Get the router
        $router = $app->make(Router::class);
        
        // Dispatch the request to the appropriate service
        // The router will need to be modified to support service-based routing
        return $router->dispatch($request);
    }
}