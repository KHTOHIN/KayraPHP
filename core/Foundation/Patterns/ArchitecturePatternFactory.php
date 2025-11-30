<?php

namespace Kayra\Foundation\Patterns;

use Kayra\Foundation\Application;

/**
 * Factory for creating architecture pattern instances
 * 
 * This class is responsible for creating the appropriate architecture pattern
 * instance based on the configuration.
 */
class ArchitecturePatternFactory
{
    /**
     * Create an architecture pattern instance based on the configuration
     * 
     * @param Application $app The application instance
     * @return ArchitecturePattern The architecture pattern instance
     */
    public static function create(Application $app): ArchitecturePattern
    {
        // Get the architecture pattern from the configuration
        $config = require $app->basePath() . '/config/app.php';
        $architecture = $config['architecture'] ?? 'mvc';
        
        // Create the appropriate pattern instance
        return match (strtolower($architecture)) {
            'mvc' => new MvcPattern(),
            'factory-service' => new FactoryServicePattern(),
            'domain-driven' => new DomainDrivenPattern(),
            'custom' => new CustomHybridPattern(),
            default => new MvcPattern(), // Default to MVC if the pattern is not recognized
        };
    }
}