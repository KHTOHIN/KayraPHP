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
            'list'          => ListCommand::class,
            'key:generate'  => KeyGenerateCommand::class,
            'cache:clear'   => CacheClearCommand::class,
            'route:list'    => RouteListCommand::class,
            'serve'         => ServeCommand::class,
        ]);
    }

    protected function register(array $commands): void
    {
        $this->commands = $commands;
    }

    public function handle(array $argv): void
    {
        $command = $argv[1] ?? 'list';
        $args = array_slice($argv, 2);

        if (!isset($this->commands[$command])) {
            echo "Command '{$command}' not found.\n\n";
            $this->commands['list']::run($this->app, []);
            return;
        }

        $class = $this->commands[$command];
        $class::run($this->app, $args);
    }
}