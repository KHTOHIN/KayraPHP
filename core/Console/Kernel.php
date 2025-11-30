<?php

namespace Kayra\Console;

use Kayra\Foundation\Application;
use Kayra\Console\Commands\{
    ListCommand,
    KeyGenerateCommand,
    CacheClearCommand,
    RouteListCommand,
    ServeCommand
};

class Kernel
{
    protected Application $app;
    protected array $commands = [];

    public function __construct(Application $app)
    {
        $this->app = $app;

        $this->register([
            'list'              => ListCommand::class,
            'key:generate'      => KeyGenerateCommand::class,
            'cache:clear'       => CacheClearCommand::class,
            'route:list'        => RouteListCommand::class,
            'serve'             => ServeCommand::class,
            'make:controller'   => MakeControllerCommand::class,
            'make:model'        => MakeModelCommand::class,
            'migrate'           => MigrateCommand::class,
            'container:compile' => ContainerCompileCommand::class,
        ]);
    }

    /**
     * Static method to compile the container during composer post-autoload-dump
     */
    public static function compileContainer(): void
    {
        $basePath = dirname(__DIR__, 3); // Go up 3 levels from core/Console/Kernel.php

        // Load config files
        $configs = [];
        foreach (['app', 'cache', 'database', 'storage'] as $config) {
            $configFile = "{$basePath}/config/{$config}.php";
            if (file_exists($configFile)) {
                $configs[$config] = require $configFile;
            }
        }

        // Create container and compiler
        $container = new \Kayra\Container\Container();
        $compiler = new \Kayra\Container\Compiler($container);

        // Compile basic services
        $compiler->compileBasicServices($configs);

        echo "Container compiled successfully.\n";
    }

    /**
     * Register command mappings.
     */
    protected function register(array $commands): void
    {
        $this->commands = $commands;
    }

    /**
     * Handle CLI input.
     */
    public function handle(array $argv): void
    {
        $command = $argv[1] ?? 'list';
        $args = array_slice($argv, 2);

        if (!isset($this->commands[$command])) {
            $this->printError("Command '{$command}' not found.");
            $this->runListCommand();
            return;
        }

        $class = $this->commands[$command];

        if (!class_exists($class)) {
            $this->printError("Command class not found: {$class}");
            return;
        }

        if (!method_exists($class, 'run')) {
            $this->printError("Invalid command: {$class} must implement static run(Application \$app, array \$args).");
            return;
        }

        $class::run($this->app, $args);
    }

    /**
     * Print a styled error message.
     */
    protected function printError(string $message): void
    {
        echo "❌ {$message}\n\n";
    }

    /**
     * Fallback to the list command when invalid input.
     */
    protected function runListCommand(): void
    {
        if (isset($this->commands['list'])) {
            $list = $this->commands['list'];
            $list::run($this->app, []);
        } else {
            echo "Available commands:\n";
            foreach (array_keys($this->commands) as $cmd) {
                echo "  - {$cmd}\n";
            }
        }
    }
}
