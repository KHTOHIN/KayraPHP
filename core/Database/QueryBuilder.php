<?php

declare(strict_types=1);

namespace Kayra\Database;

use Closure;
use InvalidArgumentException;

/**
 * Fluent SQL builder.
 *
 * Two rules hold everywhere in this class:
 *
 *  1. Every *value* becomes a bound parameter. No value is ever interpolated
 *     into SQL, so there is no escaping to get wrong.
 *  2. Every *identifier* — table, column, direction, operator — is validated
 *     against an allow-list by {@see Grammar} before it reaches the string.
 *     Identifiers cannot be bound, so the only safe handling is to reject
 *     anything that is not a plain name.
 *
 * Together those mean user input cannot alter query structure, which is the
 * whole of SQL injection.
 */
final class QueryBuilder
{
    /** @var list<string> */
    private array $columns = ['*'];

    private bool $distinct = false;

    /** @var list<array{type: string, sql: string, boolean: string}> */
    private array $wheres = [];

    /** @var list<mixed> */
    private array $whereBindings = [];

    /** @var list<string> */
    private array $joins = [];

    /** @var list<mixed> */
    private array $joinBindings = [];

    /** @var list<string> */
    private array $groups = [];

    /** @var list<string> */
    private array $havings = [];

    /** @var list<mixed> */
    private array $havingBindings = [];

    /** @var list<string> */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private readonly Grammar $grammar;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {
        $this->grammar = $connection->grammar();
    }

    /* --------------------------------------------------------------------
     | Selection
     * -------------------------------------------------------------------- */

    public function select(string ...$columns): self
    {
        $this->columns = $columns === [] ? ['*'] : array_values($columns);

        return $this;
    }

    public function addSelect(string ...$columns): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        foreach ($columns as $column) {
            $this->columns[] = $column;
        }

        return $this;
    }

    public function distinct(): self
    {
        $this->distinct = true;

        return $this;
    }

    /* --------------------------------------------------------------------
     | Conditions
     * -------------------------------------------------------------------- */

    /**
     * Add a WHERE condition.
     *
     * Two-argument form implies equality: where('id', 5).
     * A closure groups nested conditions: where(fn ($q) => $q->where(...)->orWhere(...)).
     */
    public function where(string|Closure $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): self
    {
        if ($column instanceof Closure) {
            return $this->whereNested($column, $boolean);
        }

        // where('col', 'value') — the middle argument is the value.
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->addWhere($column, $operator, $value, $boolean);
    }

    /**
     * Shared by where() and orWhere().
     *
     * The shorthand check has to happen in each public entry point rather than
     * here: func_num_args() reports the arguments of the frame it runs in, so
     * delegating would always see the full four.
     */
    private function addWhere(string $column, mixed $operator, mixed $value, string $boolean): self
    {
        $operator = $this->grammar->operator((string) $operator);

        if ($value === null && in_array($operator, ['=', '!=', '<>'], true)) {
            // `col = NULL` is never true in SQL; the caller meant IS NULL.
            return $this->whereNull($column, $boolean, $operator !== '=');
        }

        $this->wheres[] = [
            'type'    => 'basic',
            'sql'     => $this->grammar->wrap($column) . ' ' . $operator . ' ?',
            'boolean' => $this->boolean($boolean),
        ];

        $this->whereBindings[] = $value;

        return $this;
    }

    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        if ($column instanceof Closure) {
            return $this->whereNested($column, 'OR');
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->addWhere($column, $operator, $value, 'OR');
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): self
    {
        if ($values === []) {
            // An empty IN () is a syntax error in most dialects. `IN ()` means
            // "matches nothing", so express that directly.
            $this->wheres[] = [
                'type'    => 'raw',
                'sql'     => $not ? '1 = 1' : '1 = 0',
                'boolean' => $this->boolean($boolean),
            ];

            return $this;
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $this->wheres[] = [
            'type'    => 'in',
            'sql'     => $this->grammar->wrap($column) . ($not ? ' NOT IN (' : ' IN (') . $placeholders . ')',
            'boolean' => $this->boolean($boolean),
        ];

        foreach ($values as $value) {
            $this->whereBindings[] = $value;
        }

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): self
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    public function whereNull(string $column, string $boolean = 'AND', bool $not = false): self
    {
        $this->wheres[] = [
            'type'    => 'null',
            'sql'     => $this->grammar->wrap($column) . ($not ? ' IS NOT NULL' : ' IS NULL'),
            'boolean' => $this->boolean($boolean),
        ];

        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        return $this->whereNull($column, $boolean, true);
    }

    public function whereBetween(string $column, mixed $from, mixed $to, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type'    => 'between',
            'sql'     => $this->grammar->wrap($column) . ' BETWEEN ? AND ?',
            'boolean' => $this->boolean($boolean),
        ];

        $this->whereBindings[] = $from;
        $this->whereBindings[] = $to;

        return $this;
    }

    /**
     * Compare two columns rather than a column and a value.
     */
    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type'    => 'column',
            'sql'     => $this->grammar->wrap($first) . ' ' . $this->grammar->operator($operator) . ' ' . $this->grammar->wrap($second),
            'boolean' => $this->boolean($boolean),
        ];

        return $this;
    }

    private function whereNested(Closure $callback, string $boolean): self
    {
        $nested = new self($this->connection, $this->table);
        $callback($nested);

        if ($nested->wheres === []) {
            return $this;
        }

        $this->wheres[] = [
            'type'    => 'nested',
            'sql'     => '(' . $nested->compileWheresBody() . ')',
            'boolean' => $this->boolean($boolean),
        ];

        foreach ($nested->whereBindings as $binding) {
            $this->whereBindings[] = $binding;
        }

        return $this;
    }

    private function boolean(string $boolean): string
    {
        $normalised = strtoupper(trim($boolean));

        if (!in_array($normalised, ['AND', 'OR'], true)) {
            throw new InvalidArgumentException("[{$boolean}] is not a valid boolean operator.");
        }

        return $normalised;
    }

    /* --------------------------------------------------------------------
     | Joins, grouping, ordering
     * -------------------------------------------------------------------- */

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $normalised = strtoupper(trim($type));

        if (!in_array($normalised, ['INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS'], true)) {
            throw new InvalidArgumentException("[{$type}] is not a valid join type.");
        }

        $this->joins[] = sprintf(
            '%s JOIN %s ON %s %s %s',
            $normalised,
            $this->grammar->wrap($table),
            $this->grammar->wrap($first),
            $this->grammar->operator($operator),
            $this->grammar->wrap($second),
        );

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groups[] = $this->grammar->wrap($column);
        }

        return $this;
    }

    public function having(string $column, string $operator, mixed $value): self
    {
        $this->havings[] = $this->grammar->wrap($column) . ' ' . $this->grammar->operator($operator) . ' ?';
        $this->havingBindings[] = $value;

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orders[] = $this->grammar->wrap($column) . ' ' . $this->grammar->direction($direction);

        return $this;
    }

    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * Page through results, 1-indexed.
     */
    public function forPage(int $page, int $perPage = 15): self
    {
        return $this->offset((max(1, $page) - 1) * $perPage)->limit($perPage);
    }

    /* --------------------------------------------------------------------
     | Compilation
     * -------------------------------------------------------------------- */

    public function toSql(): string
    {
        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '')
            . implode(', ', array_map($this->grammar->wrap(...), $this->columns))
            . ' FROM ' . $this->grammar->wrap($this->table);

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $wheres = $this->compileWheresBody();

        if ($wheres !== '') {
            $sql .= ' WHERE ' . $wheres;
        }

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if ($this->havings !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $this->havings);
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        return $sql . $this->grammar->compileLimit($this->limit, $this->offset);
    }

    private function compileWheresBody(): string
    {
        $sql = '';

        foreach ($this->wheres as $index => $where) {
            $sql .= ($index === 0 ? '' : ' ' . $where['boolean'] . ' ') . $where['sql'];
        }

        return $sql;
    }

    /**
     * @return list<mixed>
     */
    public function bindings(): array
    {
        return [...$this->joinBindings, ...$this->whereBindings, ...$this->havingBindings];
    }

    /* --------------------------------------------------------------------
     | Execution
     * -------------------------------------------------------------------- */

    /**
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        return $this->connection->select($this->toSql(), $this->bindings());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        return $this->limit(1)->connection->selectOne($this->toSql(), $this->bindings());
    }

    public function value(string $column): mixed
    {
        $row = $this->select($column)->first();

        return $row === null ? null : ($row[$column] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(mixed $id, string $column = 'id'): ?array
    {
        return $this->where($column, $id)->first();
    }

    public function exists(): bool
    {
        return $this->limit(1)->connection->selectOne($this->toSql(), $this->bindings()) !== null;
    }

    /**
     * Stream results without loading them all into memory.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function cursor(): \Generator
    {
        return $this->connection->cursor($this->toSql(), $this->bindings());
    }

    /**
     * Process results in batches.
     *
     * Ordering by a stable column is required: without it, LIMIT/OFFSET paging
     * can skip or repeat rows as the table changes underneath.
     *
     * @param callable(list<array<string, mixed>>, int): (bool|null) $callback
     *        Return false to stop early.
     */
    public function chunk(int $size, callable $callback): void
    {
        if ($this->orders === []) {
            throw new InvalidArgumentException(
                'chunk() requires an orderBy(): without a stable order, paging can skip or repeat rows.',
            );
        }

        $page = 1;

        do {
            $rows = (clone $this)->forPage($page, $size)->get();

            if ($rows === []) {
                return;
            }

            if ($callback($rows, $page) === false) {
                return;
            }

            $page++;
        } while (count($rows) === $size);
    }

    /* --------------------------------------------------------------------
     | Aggregates
     * -------------------------------------------------------------------- */

    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    public function sum(string $column): float
    {
        return (float) $this->aggregate('SUM', $column);
    }

    public function avg(string $column): float
    {
        return (float) $this->aggregate('AVG', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    private function aggregate(string $function, string $column): mixed
    {
        // $function is never caller-supplied; $column is wrapped.
        $clone = clone $this;
        $clone->columns = ['*'];
        $clone->orders = [];
        $clone->limit = null;
        $clone->offset = null;

        $expression = $function . '(' . ($column === '*' ? '*' : $this->grammar->wrap($column)) . ')';

        $sql = 'SELECT ' . $expression . ' AS aggregate FROM ' . $this->grammar->wrap($this->table);

        if ($clone->joins !== []) {
            $sql .= ' ' . implode(' ', $clone->joins);
        }

        $wheres = $clone->compileWheresBody();

        if ($wheres !== '') {
            $sql .= ' WHERE ' . $wheres;
        }

        $row = $this->connection->selectOne($sql, $clone->bindings());

        return $row['aggregate'] ?? null;
    }

    /* --------------------------------------------------------------------
     | Writes
     * -------------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $values
     */
    public function insert(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $columns = array_keys($values);

        $sql = 'INSERT INTO ' . $this->grammar->wrap($this->table)
            . ' (' . implode(', ', array_map($this->grammar->wrap(...), $columns)) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')';

        return $this->connection->statement($sql, array_values($values));
    }

    /**
     * Insert and return the generated id.
     *
     * @param array<string, mixed> $values
     */
    public function insertGetId(array $values, string $sequence = 'id'): string
    {
        $this->insert($values);

        return $this->connection->lastInsertId(
            $this->connection->grammar() instanceof PostgresGrammar
                ? $this->table . '_' . $sequence . '_seq'
                : null,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function insertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        $placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $bindings = [];

        foreach ($rows as $row) {
            foreach ($columns as $column) {
                // Missing keys become NULL rather than shifting the columns.
                $bindings[] = $row[$column] ?? null;
            }
        }

        $sql = 'INSERT INTO ' . $this->grammar->wrap($this->table)
            . ' (' . implode(', ', array_map($this->grammar->wrap(...), $columns)) . ')'
            . ' VALUES ' . implode(', ', array_fill(0, count($rows), $placeholder));

        return $this->connection->statement($sql, $bindings);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        // An UPDATE with no WHERE rewrites the whole table. Requiring one is a
        // guard rail, not a limitation: pass a tautology deliberately if that
        // really is the intent.
        if ($this->wheres === []) {
            throw new InvalidArgumentException(
                'update() without a where() would modify every row. '
                . 'Add a condition, or call updateAll() to say you mean it.',
            );
        }

        return $this->connection->statement(
            $this->compileUpdate($values),
            [...array_values($values), ...$this->bindings()],
        );
    }

    /**
     * Update every row, deliberately.
     *
     * @param array<string, mixed> $values
     */
    public function updateAll(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        return $this->connection->statement(
            $this->compileUpdate($values),
            [...array_values($values), ...$this->bindings()],
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function compileUpdate(array $values): string
    {
        $assignments = implode(', ', array_map(
            fn (string $column): string => $this->grammar->wrap($column) . ' = ?',
            array_keys($values),
        ));

        $sql = 'UPDATE ' . $this->grammar->wrap($this->table) . ' SET ' . $assignments;
        $wheres = $this->compileWheresBody();

        return $wheres === '' ? $sql : $sql . ' WHERE ' . $wheres;
    }

    public function delete(): int
    {
        // Same reasoning as update().
        if ($this->wheres === []) {
            throw new InvalidArgumentException(
                'delete() without a where() would remove every row. '
                . 'Add a condition, or call truncate() to say you mean it.',
            );
        }

        return $this->connection->statement(
            'DELETE FROM ' . $this->grammar->wrap($this->table) . ' WHERE ' . $this->compileWheresBody(),
            $this->bindings(),
        );
    }

    public function truncate(): void
    {
        $this->connection->statement('DELETE FROM ' . $this->grammar->wrap($this->table));
    }
}
