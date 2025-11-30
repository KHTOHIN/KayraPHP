<?php

namespace Kayra\Foundation\Patterns;

use Kayra\Foundation\Application;
use Kayra\Http\Request;
use Kayra\Http\Response;
use Kayra\Http\Router;

/**
 * Domain-Driven Design (DDD) architecture pattern implementation
 * 
 * This pattern organizes the application around business domains:
 * - Each domain has its own models, services, and repositories
 * - Domains are isolated and communicate through well-defined interfaces
 * - The pattern emphasizes the core domain and domain logic
 */
class DomainDrivenPattern implements ArchitecturePattern
{
    /**
     * Initialize the Domain-Driven pattern
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
     * Configure routing for the Domain-Driven pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function configureRouting(Application $app): void
    {
        // Domain-Driven uses domain-based routing
        // Routes are organized by domain
        $router = $app->make(Router::class);
        
        // Load domain-specific route files
        $routeDir = $app->basePath() . '/routes';
        $domainRoutes = glob($routeDir . '/domains/*.php');
        
        if (!empty($domainRoutes)) {
            $router->loadRoutes($domainRoutes);
        } else {
            // Fall back to standard routes if no domain routes exist
            $router->loadRoutes();
        }
    }
    
    /**
     * Configure module loading for the Domain-Driven pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function configureModuleLoading(Application $app): void
    {
        // Domain-Driven modules are organized in app/Domains
        // Each domain has its own models, services, and repositories
        $domainsDir = $app->basePath() . '/app/Domains';
        
        if (!is_dir($domainsDir)) {
            mkdir($domainsDir, 0755, true);
        }
        
        // Create routes/domains directory if it doesn't exist
        $domainRoutesDir = $app->basePath() . '/routes/domains';
        
        if (!is_dir($domainRoutesDir)) {
            mkdir($domainRoutesDir, 0755, true);
        }
    }
    
    /**
     * Configure autoload namespaces for the Domain-Driven pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function configureAutoloadNamespaces(Application $app): void
    {
        // Domain-Driven uses App\Domains namespace
        // Each domain has its own namespace: App\Domains\{DomainName}
        // No special configuration needed as these are standard PSR-4 namespaces
    }
    
    /**
     * Handle a request using the Domain-Driven pattern
     * 
     * @param Request $request The request to handle
     * @param Application $app The application instance
     * @return Response The response
     */
    public function handleRequest(Request $request, Application $app): Response
    {
        // Get the router
        $router = $app->make(Router::class);
        
        // Dispatch the request to the appropriate domain handler
        return $router->dispatch($request);
    }
}