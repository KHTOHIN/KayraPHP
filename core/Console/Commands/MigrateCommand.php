<?php

declare(strict_types=1);

namespace Kayra\Console\Commands;

use Kayra\Console\Command;
use Kayra\Database\DatabaseManager;
use Kayra\Database\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'migrate', description: 'Run database migrations')]
final class MigrateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setAliases(['migrate:rollback', 'migrate:fresh', 'migrate:status'])
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'Connection to use')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Run destructive operations in production');
    }

    protected function run_(): int
    {
        $invoked = (string) $this->input->getFirstArgument();

        $connection = $this->app->get(DatabaseManager::class)
            ->connection(is_string($this->option('database')) ? $this->option('database') : null);

        $config = $this->app->config();

        $migrator = new Migrator(
            $connection,
            $config->string('database.migrations.path', $this->app->databasePath('migrations')),
            $config->string('database.migrations.table', 'kayra_migrations'),
        );

        return match ($invoked) {
            'migrate:status'   => $this->status($migrator),
            'migrate:rollback' => $this->rollback($migrator),
            'migrate:fresh'    => $this->fresh($migrator),
            default            => $this->migrate($migrator),
        };
    }

    private function migrate(Migrator $migrator): int
    {
        $this->io->newLine();

        $applied = $migrator->run(function (string $name): void {
            $this->io->writeln("  <fg=green>✓</> {$name}");
        });

        $this->io->newLine();
        $this->io->writeln($applied === []
            ? '  <fg=gray>Nothing to migrate.</>'
            : sprintf('  <fg=gray>%d migration(s) applied.</>', count($applied)));
        $this->io->newLine();

        return self::SUCCESS;
    }

    private function rollback(Migrator $migrator): int
    {
        if (!$this->confirmDestructive('roll back the last migration batch')) {
            return self::FAILURE;
        }

        $this->io->newLine();

        $reversed = $migrator->rollback(function (string $name): void {
            $this->io->writeln("  <fg=yellow>↺</> {$name}");
        });

        $this->io->newLine();
        $this->io->writeln($reversed === []
            ? '  <fg=gray>Nothing to roll back.</>'
            : sprintf('  <fg=gray>%d migration(s) reversed.</>', count($reversed)));
        $this->io->newLine();

        return self::SUCCESS;
    }

    private function fresh(Migrator $migrator): int
    {
        if (!$this->confirmDestructive('DROP every table and re-run all migrations')) {
            return self::FAILURE;
        }

        $this->io->newLine();

        $migrator->fresh(function (string $name): void {
            $this->io->writeln("  <fg=green>✓</> {$name}");
        });

        $this->io->newLine();

        return self::SUCCESS;
    }

    private function status(Migrator $migrator): int
    {
        $applied = $migrator->appliedNames();
        $rows = [];

        foreach ($migrator->files() as $name => $ignored) {
            $rows[] = [in_array($name, $applied, true) ? 'applied' : 'pending', $name];
        }

        if ($rows === []) {
            $this->io->warning('No migrations found.');

            return self::SUCCESS;
        }

        $this->io->newLine();
        $this->io->table(['Status', 'Migration'], $rows);

        return self::SUCCESS;
    }

    /**
     * Refuse destructive operations in production unless --force is given.
     *
     * `migrate:fresh` against a production database is unrecoverable, so the
     * confirmation is a guard rail rather than a formality.
     */
    private function confirmDestructive(string $description): bool
    {
        if (!$this->app->isProduction() || $this->option('force') === true) {
            return true;
        }

        $this->io->warning("APP_ENV is production. This will {$description}.");

        return $this->io->confirm('Are you absolutely sure?', false);
    }
}
