<?php

declare(strict_types=1);

namespace Kayra\Database;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * A single database connection.
 *
 * PDO is configured so that failures throw, results are associative arrays, and
 * — critically — emulated prepares are off. With emulation on, PDO interpolates
 * parameters client-side, which reintroduces the injection surface that
 * prepared statements exist to remove and silently changes how types are bound.
 */
final class Connection
{
    private ?PDO $pdo = null;

    /** @var list<array{sql: string, bindings: list<mixed>, time: float}> */
    private array $log = [];

    private bool $logging = false;

    private int $transactions = 0;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly Grammar $grammar,
    ) {
    }

    public function grammar(): Grammar
    {
        return $this->grammar;
    }

    /**
     * The underlying PDO handle, connecting on first use.
     */
    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    private function connect(): PDO
    {
        $driver = (string) ($this->config['driver'] ?? 'sqlite');

        try {
            $pdo = new PDO(
                $this->dsn($driver),
                isset($this->config['username']) ? (string) $this->config['username'] : null,
                isset($this->config['password']) ? (string) $this->config['password'] : null,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Real prepared statements only. See the class docblock.
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ],
            );
        } catch (PDOException $e) {
            // The DSN can contain a host and database name but never a password;
            // re-throwing PDO's message directly would still be more than the
            // application needs to see.
            throw new QueryException(
                "Unable to connect to the [{$driver}] database.",
                previous: $e,
            );
        }

        if ($driver === 'sqlite') {
            // Foreign keys are off by default in SQLite, which quietly disables
            // every constraint the schema declares.
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        return $pdo;
    }

    private function dsn(string $driver): string
    {
        if (isset($this->config['dsn']) && is_string($this->config['dsn'])) {
            return $this->config['dsn'];
        }

        $get = fn (string $key, string $default = ''): string => isset($this->config[$key])
            ? (string) $this->config[$key]
            : $default;

        return match ($driver) {
            'sqlite' => 'sqlite:' . $get('database', ':memory:'),
            'mysql', 'mariadb' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $get('host', '127.0.0.1'),
                $get('port', '3306'),
                $get('database'),
                $get('charset', 'utf8mb4'),
            ),
            'pgsql', 'postgres', 'postgresql' => sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $get('host', '127.0.0.1'),
                $get('port', '5432'),
                $get('database'),
            ),
            'sqlsrv', 'mssql' => sprintf(
                'sqlsrv:Server=%s,%s;Database=%s',
                $get('host', '127.0.0.1'),
                $get('port', '1433'),
                $get('database'),
            ),
            default => throw new QueryException("Unsupported database driver [{$driver}]."),
        };
    }

    /* --------------------------------------------------------------------
     | Queries
     * -------------------------------------------------------------------- */

    /**
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->run($sql, $bindings);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /**
     * @param list<mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch();

        /** @var array<string, mixed>|false $row */
        return $row === false ? null : $row;
    }

    /**
     * Stream rows one at a time.
     *
     * The whole result never lands in memory, which is what makes exporting a
     * large table possible at all.
     *
     * @param list<mixed> $bindings
     * @return \Generator<int, array<string, mixed>>
     */
    public function cursor(string $sql, array $bindings = []): \Generator
    {
        $statement = $this->run($sql, $bindings);

        while (($row = $statement->fetch()) !== false) {
            /** @var array<string, mixed> $row */
            yield $row;
        }
    }

    /**
     * @param list<mixed> $bindings
     * @return int Affected rows.
     */
    public function statement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return (string) $this->pdo()->lastInsertId($sequence);
    }

    /**
     * @param list<mixed> $bindings
     */
    private function run(string $sql, array $bindings): PDOStatement
    {
        $start = hrtime(true);

        try {
            $statement = $this->pdo()->prepare($sql);

            foreach ($bindings as $index => $value) {
                // Bind by explicit type so that integers stay integers: with
                // emulation off, binding an int as a string can defeat an index.
                $statement->bindValue(
                    $index + 1,
                    $value,
                    match (true) {
                        is_int($value)  => PDO::PARAM_INT,
                        is_bool($value) => PDO::PARAM_BOOL,
                        $value === null => PDO::PARAM_NULL,
                        default         => PDO::PARAM_STR,
                    },
                );
            }

            $statement->execute();
        } catch (PDOException $e) {
            throw new QueryException(
                'Database query failed: ' . $e->getMessage(),
                $sql,
                $bindings,
                $e,
            );
        }

        if ($this->logging) {
            $this->log[] = [
                'sql'      => $sql,
                'bindings' => $bindings,
                'time'     => (hrtime(true) - $start) / 1e6,
            ];
        }

        return $statement;
    }

    /* --------------------------------------------------------------------
     | Transactions
     * -------------------------------------------------------------------- */

    /**
     * Run a callback inside a transaction, committing on success.
     *
     * Nested calls use savepoints, so an inner failure rolls back only its own
     * work rather than silently committing the outer transaction.
     *
     * @template T
     * @param callable(Connection): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (Throwable $e) {
            $this->rollBack();

            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        if ($this->transactions === 0) {
            $this->pdo()->beginTransaction();
        } else {
            $this->pdo()->exec('SAVEPOINT kayra_sp_' . $this->transactions);
        }

        $this->transactions++;
    }

    public function commit(): void
    {
        if ($this->transactions === 0) {
            return;
        }

        $this->transactions--;

        if ($this->transactions === 0) {
            $this->pdo()->commit();
        } else {
            $this->pdo()->exec('RELEASE SAVEPOINT kayra_sp_' . $this->transactions);
        }
    }

    public function rollBack(): void
    {
        if ($this->transactions === 0) {
            return;
        }

        $this->transactions--;

        if ($this->transactions === 0) {
            $this->pdo()->rollBack();
        } else {
            $this->pdo()->exec('ROLLBACK TO SAVEPOINT kayra_sp_' . $this->transactions);
        }
    }

    public function transactionLevel(): int
    {
        return $this->transactions;
    }

    /* --------------------------------------------------------------------
     | Diagnostics
     * -------------------------------------------------------------------- */

    public function enableQueryLog(): void
    {
        $this->logging = true;
    }

    /**
     * @return list<array{sql: string, bindings: list<mixed>, time: float}>
     */
    public function queryLog(): array
    {
        return $this->log;
    }

    public function flushQueryLog(): void
    {
        $this->log = [];
    }

    /**
     * Start a query against a table.
     */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    /**
     * Release the underlying handle.
     *
     * A long-running worker calls this when recycling; the next query reconnects.
     */
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->transactions = 0;
    }
}
