<?php

namespace Kayra\Foundation\Patterns;

use Kayra\Foundation\Application;
use Kayra\Http\Request;
use Kayra\Http\Response;
use Kayra\Http\Router;

/**
 * MVC (Model-View-Controller) architecture pattern implementation
 * 
 * This pattern separates the application into three main components:
 * - Models: Represent the data and business logic
 * - Views: Represent the presentation layer
 * - Controllers: Handle user input and coordinate between models and views
 */
class MvcPattern implements ArchitecturePattern
{
    /**
     * Initialize the MVC pattern
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
     * Configure routing for the MVC pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function configureRouting(Application $app): void
    {
        // MVC uses the standard routing with controller@method handlers
        $router = $app->make(Router::class);
        $router->loadRoutes();
    }
    
    /**
     * Configure module loading for the MVC pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function configureModuleLoading(Application $app): void
    {
        // MVC modules are organized in app/Controllers, app/Models, and resources/views
        // No special configuration needed as this is the default structure
    }
    
    /**
     * Configure autoload namespaces for the MVC pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function configureAutoloadNamespaces(Application $app): void
    {
        // MVC uses the App namespace for controllers and models
        // No special configuration needed as this is the default structure
    }
    
    /**
     * Handle a request using the MVC pattern
     * 
     * @param Request $request The request to handle
     * @param Application $app The application instance
     * @return Response The response
     */
    public function handleRequest(Request $request, Application $app): Response
    {
        // Get the router
        $router = $app->make(Router::class);
        
        // Dispatch the request to the appropriate controller
        return $router->dispatch($request);
    }
}