<?php

declare(strict_types=1);

namespace Kayra\Database;

use RuntimeException;
use Throwable;

/**
 * Applies and reverses migrations.
 *
 * Applied migrations are recorded in a table with a batch number, so a rollback
 * reverses one deployment's worth of changes rather than one file — which is
 * what you actually want when a release goes wrong.
 */
final class Migrator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $path,
        private readonly string $table = 'kayra_migrations',
    ) {
    }

    /**
     * Create the ledger table if this is the first run.
     */
    public function ensureRepository(): void
    {
        $schema = new Schema($this->connection);

        if ($schema->hasTable($this->table)) {
            return;
        }

        $schema->create($this->table, static function (Blueprint $table): void {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
            $table->timestamp('applied_at')->nullable();
            $table->unique('migration');
        });
    }

    /**
     * Apply everything not yet applied.
     *
     * @param callable(string): void|null $onEach Progress reporter.
     * @return list<string> Migrations applied, in order.
     */
    public function run(?callable $onEach = null): array
    {
        $this->ensureRepository();

        $pending = $this->pending();

        if ($pending === []) {
            return [];
        }

        $batch = $this->nextBatch();
        $applied = [];

        foreach ($pending as $name => $migration) {
            $this->runMigration($migration, 'up');

            $this->connection->table($this->table)->insert([
                'migration'  => $name,
                'batch'      => $batch,
                'applied_at' => gmdate('Y-m-d H:i:s'),
            ]);

            $applied[] = $name;
            if ($onEach !== null) {
                $onEach($name);
            }
        }

        return $applied;
    }

    /**
     * Reverse the most recent batch.
     *
     * @param callable(string): void|null $onEach
     * @return list<string> Migrations reversed, most recent first.
     */
    public function rollback(?callable $onEach = null): array
    {
        $this->ensureRepository();

        $batch = $this->lastBatch();

        if ($batch === 0) {
            return [];
        }

        $rows = $this->connection->table($this->table)
            ->where('batch', $batch)
            ->orderBy('id', 'DESC')
            ->get();

        $reversed = [];

        foreach ($rows as $row) {
            $name = (string) $row['migration'];
            $file = $this->path . DIRECTORY_SEPARATOR . $name . '.php';

            if (!is_file($file)) {
                throw new RuntimeException(
                    "Cannot roll back [{$name}]: its file is missing. "
                    . 'Restore it, or remove the row from the migrations table by hand.',
                );
            }

            $this->runMigration($this->resolve($file), 'down');

            $this->connection->table($this->table)->where('migration', $name)->delete();

            $reversed[] = $name;
            if ($onEach !== null) {
                $onEach($name);
            }
        }

        return $reversed;
    }

    /**
     * Roll everything back, then re-run.
     *
     * @param callable(string): void|null $onEach
     */
    public function fresh(?callable $onEach = null): array
    {
        $this->ensureRepository();

        while ($this->lastBatch() > 0) {
            $this->rollback($onEach);
        }

        return $this->run($onEach);
    }

    /**
     * Migrations on disk that have not been applied.
     *
     * @return array<string, Migration>
     */
    public function pending(): array
    {
        $applied = $this->appliedNames();
        $pending = [];

        foreach ($this->files() as $name => $file) {
            if (!in_array($name, $applied, true)) {
                $pending[$name] = $this->resolve($file);
            }
        }

        return $pending;
    }

    /**
     * @return array<string, string> name => path, ordered by filename.
     */
    public function files(): array
    {
        if (!is_dir($this->path)) {
            return [];
        }

        $files = glob($this->path . DIRECTORY_SEPARATOR . '*.php') ?: [];

        // Filenames start with a timestamp, so sorting them orders by time.
        sort($files);

        $result = [];

        foreach ($files as $file) {
            $result[basename($file, '.php')] = $file;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function appliedNames(): array
    {
        $this->ensureRepository();

        return array_map(
            static fn (array $row): string => (string) $row['migration'],
            $this->connection->table($this->table)->orderBy('id')->get(),
        );
    }

    private function runMigration(Migration $migration, string $direction): void
    {
        $migration->setConnection($this->connection);

        // MySQL cannot roll back DDL, so a transaction there is theatre; where
        // it does work, a failed migration must not leave the schema half-changed.
        $transactional = $migration->withinTransaction()
            && !$this->connection->grammar() instanceof MySqlGrammar;

        if (!$transactional) {
            $migration->{$direction}();

            return;
        }

        $this->connection->beginTransaction();

        try {
            $migration->{$direction}();
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    private function resolve(string $file): Migration
    {
        $migration = require $file;

        if (!$migration instanceof Migration) {
            throw new RuntimeException(
                'Migration [' . basename($file) . '] must return an instance of ' . Migration::class . '.',
            );
        }

        return $migration;
    }

    private function nextBatch(): int
    {
        return $this->lastBatch() + 1;
    }

    private function lastBatch(): int
    {
        return (int) ($this->connection->table($this->table)->max('batch') ?? 0);
    }
}
