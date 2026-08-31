<?php

declare(strict_types=1);

namespace Kayra\Console;

use Kayra\Console\Commands\AboutCommand;
use Kayra\Console\Commands\ClearCommand;
use Kayra\Console\Commands\DoctorCommand;
use Kayra\Console\Commands\KeyGenerateCommand;
use Kayra\Console\Commands\MakeCommand;
use Kayra\Console\Commands\MigrateCommand;
use Kayra\Console\Commands\OptimizeCommand;
use Kayra\Console\Commands\RouteListCommand;
use Kayra\Console\Commands\ServeCommand;
use Kayra\Console\Commands\TestCommand;
use Kayra\Foundation\Application;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;

/**
 * The `kayra` command-line entry point.
 *
 * Commands are resolved through the container, so a command can type-hint any
 * service exactly like a controller can.
 */
final class Kernel
{
    /** @var list<class-string<Command>> */
    private const COMMANDS = [
        ServeCommand::class,
        RouteListCommand::class,
        MakeCommand::class,
        OptimizeCommand::class,
        ClearCommand::class,
        KeyGenerateCommand::class,
        DoctorCommand::class,
        AboutCommand::class,
        MigrateCommand::class,
        TestCommand::class,
    ];

    private readonly ConsoleApplication $console;

    public function __construct(private readonly Application $app)
    {
        $this->console = new ConsoleApplication('KayraPHP', Application::VERSION);

        foreach (self::COMMANDS as $command) {
            $this->console->add($this->app->make($command));
        }

        // Project-supplied commands, from config/app.php.
        foreach ($this->app->config()->array('app.commands', []) as $command) {
            if (is_string($command) && class_exists($command)) {
                $this->console->add($this->app->make($command));
            }
        }

        // routes/console.php may register closures as commands.
        $this->loadConsoleRoutes();
    }

    private function loadConsoleRoutes(): void
    {
        $file = $this->app->routesPath('console.php');

        if (is_file($file)) {
            (function (string $path): void {
                $console = $this->console;
                $app = $this->app;

                require $path;
            })($file);
        }
    }

    public function console(): ConsoleApplication
    {
        return $this->console;
    }

    /**
     * @param list<string>|null $argv
     */
    public function handle(?array $argv = null): int
    {
        $input = new ArgvInput($argv);
        $output = new ConsoleOutput();

        try {
            return $this->console->run($input, $output);
        } catch (Throwable $e) {
            $output->getErrorOutput()->writeln('<error>' . $e->getMessage() . '</error>');

            if ($this->app->isDebug()) {
                $output->getErrorOutput()->writeln($e->getTraceAsString());
            }

            return 1;
        }
    }
}
