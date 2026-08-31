<?php

declare(strict_types=1);

namespace Kayra\Database;

use Closure;

/**
 * A small schema builder.
 *
 * Deliberately limited to the column types and constraints that behave the same
 * way across MySQL, PostgreSQL and SQLite. Anything beyond that — partial
 * indexes, generated columns, database-specific types — is better written as
 * explicit SQL in the migration than hidden behind a DSL that quietly differs
 * per dialect. {@see Migration::statement()} is the escape hatch, and using it
 * is not a failure.
 */
final class Schema
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param Closure(Blueprint): void $callback
     */
    public function create(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table, $this->connection->grammar());
        $callback($blueprint);

        foreach ($blueprint->toCreateSql() as $sql) {
            $this->connection->statement($sql);
        }
    }

    public function drop(string $table): void
    {
        $this->connection->statement(
            'DROP TABLE ' . $this->connection->grammar()->wrap($table),
        );
    }

    public function dropIfExists(string $table): void
    {
        $this->connection->statement(
            'DROP TABLE IF EXISTS ' . $this->connection->grammar()->wrap($table),
        );
    }

    public function hasTable(string $table): bool
    {
        $grammar = $this->connection->grammar();

        $sql = match (true) {
            $grammar instanceof SqliteGrammar   => "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            $grammar instanceof PostgresGrammar => 'SELECT table_name FROM information_schema.tables WHERE table_name = ?',
            default                             => 'SELECT table_name FROM information_schema.tables WHERE table_name = ?',
        };

        return $this->connection->selectOne($sql, [$table]) !== null;
    }

    /**
     * Rename a table.
     */
    public function rename(string $from, string $to): void
    {
        $grammar = $this->connection->grammar();

        $this->connection->statement(
            'ALTER TABLE ' . $grammar->wrap($from) . ' RENAME TO ' . $grammar->wrap($to),
        );
    }
}
