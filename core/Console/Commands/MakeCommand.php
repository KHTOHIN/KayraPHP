<?php

declare(strict_types=1);

namespace Kayra\Console\Commands;

use Kayra\Console\Command;
use Kayra\Utils\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * One generator for every artefact, rather than a class per `make:*`.
 *
 * The variations are only "which stub, which directory, which suffix", so a
 * table is clearer than nine near-identical command classes.
 */
#[AsCommand(name: 'make', description: 'Generate application classes and templates')]
final class MakeCommand extends Command
{
    /**
     * type => [directory, namespace, suffix, stub]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const TYPES = [
        'controller' => ['app/Controllers', 'App\Controllers', 'Controller', 'controller'],
        'model'      => ['app/Models', 'App\Models', '', 'model'],
        'service'    => ['app/Services', 'App\Services', 'Service', 'service'],
        'repository' => ['app/Repositories', 'App\Repositories', 'Repository', 'repository'],
        'middleware' => ['app/Middlewares', 'App\Middlewares', 'Middleware', 'middleware'],
        'provider'   => ['app/Providers', 'App\Providers', 'ServiceProvider', 'provider'],
        'command'    => ['app/Commands', 'App\Commands', 'Command', 'command'],
        'migration'  => ['database/migrations', '', '', 'migration'],
        'view'       => ['app/Views', '', '', 'view'],
    ];

    protected function configure(): void
    {
        $this
            ->setAliases(array_map(static fn (string $t): string => "make:{$t}", array_keys(self::TYPES)))
            ->addArgument('type', InputArgument::OPTIONAL, 'What to generate: ' . implode(', ', array_keys(self::TYPES)))
            ->addArgument('name', InputArgument::OPTIONAL, 'Name of the class or view')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing file')
            ->addOption('api', null, InputOption::VALUE_NONE, 'Controller: generate resource actions');
    }

    protected function run_(): int
    {
        // Called as `make:controller Post`, the type comes from the alias and the
        // first positional argument is the name. Called as `make controller Post`,
        // both are positional.
        $invoked = (string) $this->input->getFirstArgument();

        if (str_starts_with($invoked, 'make:')) {
            $type = substr($invoked, 5);
            $name = (string) ($this->argument('type') ?? '');
        } else {
            $type = (string) ($this->argument('type') ?? '');
            $name = (string) ($this->argument('name') ?? '');
        }

        if (!isset(self::TYPES[$type])) {
            $this->io->error("Unknown type [{$type}]. Available: " . implode(', ', array_keys(self::TYPES)));

            return self::INVALID;
        }

        if ($name === '') {
            $this->io->error('A name is required, e.g. `kayra make:controller Post`.');

            return self::INVALID;
        }

        [$directory, $namespace, $suffix, $stub] = self::TYPES[$type];

        if ($type === 'view') {
            return $this->makeView($name) ? self::SUCCESS : self::FAILURE;
        }

        if ($type === 'migration') {
            return $this->makeMigration($name) ? self::SUCCESS : self::FAILURE;
        }

        $class = Str::studly($name);

        // `make:controller PostController` should not produce PostControllerController.
        if ($suffix !== '' && !str_ends_with($class, $suffix)) {
            $class .= $suffix;
        }

        $path = $this->app->basePath($directory . '/' . $class . '.php');

        $contents = strtr($this->stub($stub), [
            '{{namespace}}' => $namespace,
            '{{class}}'     => $class,
            '{{name}}'      => $name,
            '{{snake}}'     => Str::snake($name),
            '{{kebab}}'     => Str::kebab($name),
        ]);

        if (!$this->writeFile($path, $contents, $this->option('force') === true)) {
            return self::FAILURE;
        }

        if ($type === 'provider') {
            $this->io->note("Add {$namespace}\\{$class}::class to the 'providers' array in config/app.php.");
        }

        return self::SUCCESS;
    }

    /**
     * Migrations are named by timestamp, not by class.
     *
     * The filename *is* the ordering: migrations run in filename order, so a
     * UTC timestamp prefix is what makes two developers' migrations interleave
     * correctly after a merge.
     */
    private function makeMigration(string $name): bool
    {
        $snake = Str::snake($name);
        $filename = gmdate('Y_m_d_His') . '_' . $snake;
        $path = $this->app->databasePath('migrations/' . $filename . '.php');

        // Guess the table name from the conventional "create_x_table" form.
        $table = preg_match('/^create_(.+)_table$/', $snake, $m) === 1
            ? $m[1]
            : (preg_match('/_(?:to|from|in)_(.+?)_table$/', $snake, $m) === 1 ? $m[1] : $snake);

        $contents = strtr($this->stub('migration'), [
            '{{snake}}' => $table,
            '{{name}}'  => $name,
        ]);

        return $this->writeFile($path, $contents, $this->option('force') === true);
    }

    private function makeView(string $name): bool
    {
        $relative = str_replace('.', '/', $name);
        $path = $this->app->appPath('Views/' . $relative . '.kayra.php');

        $title = Str::headline($name);

        $contents = <<<VIEW
            @extends('layouts.app')

            @section('title', '{$title}')

            @section('content')
                <h1>{$title}</h1>
            @endsection

            VIEW;

        return $this->writeFile($path, $contents, $this->option('force') === true);
    }

    private function stub(string $name): string
    {
        $path = __DIR__ . '/../Stubs/' . $name . '.stub';

        if (!is_file($path)) {
            throw new \RuntimeException("Missing stub [{$name}].");
        }

        return (string) file_get_contents($path);
    }
}
