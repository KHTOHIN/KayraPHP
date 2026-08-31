<?php

declare(strict_types=1);

namespace Kayra\Database;

/**
 * Base class for a migration.
 *
 * A migration describes one reversible change. `down()` is not optional
 * decoration: a migration that cannot be undone turns a bad deploy into a
 * restore-from-backup, so the base class makes writing it the default rather
 * than an afterthought.
 */
abstract class Migration
{
    protected Connection $connection;

    final public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
    }

    abstract public function up(): void;

    abstract public function down(): void;

    /**
     * Whether to wrap this migration in a transaction.
     *
     * MySQL cannot roll back DDL, so wrapping gains nothing there; PostgreSQL
     * and SQLite can, and a half-applied migration is far worse than a failed
     * one. Override to false for statements a transaction cannot contain.
     */
    public function withinTransaction(): bool
    {
        return true;
    }

    /**
     * Run raw SQL.
     *
     * @param list<mixed> $bindings
     */
    protected function statement(string $sql, array $bindings = []): int
    {
        return $this->connection->statement($sql, $bindings);
    }

    protected function schema(): Schema
    {
        return new Schema($this->connection);
    }
}
