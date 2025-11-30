<?php

namespace Kayra\Foundation\Patterns;

use Kayra\Foundation\Application;
use Kayra\Http\Request;
use Kayra\Http\Response;

/**
 * Interface for architecture patterns
 * 
 * This interface defines the methods that all architecture pattern implementations must implement.
 * Each pattern is responsible for configuring routing, module loading, and autoload namespaces.
 */
interface ArchitecturePattern
{
    /**
     * Initialize the architecture pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function initialize(Application $app): void;
    
    /**
     * Configure routing for the architecture pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function configureRouting(Application $app): void;
    
    /**
     * Configure module loading for the architecture pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function configureModuleLoading(Application $app): void;
    
    /**
     * Configure autoload namespaces for the architecture pattern
     * 
     * @param Application $app The application instance
     * @return void
     */
    public function configureAutoloadNamespaces(Application $app): void;
    
    /**
     * Handle a request using the architecture pattern
     * 
     * @param Request $request The request to handle
     * @param Application $app The application instance
     * @return Response The response
     */
    public function handleRequest(Request $request, Application $app): Response;
}