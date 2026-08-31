<?php

declare(strict_types=1);

namespace Kayra\Database;

/**
 * Describes a table being created.
 *
 * Column types are mapped per dialect. Only portable types are offered — see
 * {@see Schema} for why.
 */
final class Blueprint
{
    /** @var list<string> */
    private array $columns = [];

    /** @var list<string> */
    private array $constraints = [];

    /** @var list<array{unique: bool, columns: list<string>}> */
    private array $indexes = [];

    public function __construct(
        private readonly string $table,
        private readonly Grammar $grammar,
    ) {
    }

    /* --------------------------------------------------------------- types */

    /**
     * Auto-incrementing primary key.
     */
    public function id(string $column = 'id'): self
    {
        $definition = match (true) {
            $this->grammar instanceof SqliteGrammar   => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            $this->grammar instanceof PostgresGrammar => 'BIGSERIAL PRIMARY KEY',
            $this->grammar instanceof SqlServerGrammar => 'BIGINT IDENTITY(1,1) PRIMARY KEY',
            default                                   => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
        };

        $this->columns[] = $this->grammar->wrap($column) . ' ' . $definition;

        return $this;
    }

    public function string(string $column, int $length = 255): self
    {
        return $this->column($column, "VARCHAR({$length})");
    }

    public function text(string $column): self
    {
        return $this->column($column, 'TEXT');
    }

    public function integer(string $column): self
    {
        return $this->column($column, 'INTEGER');
    }

    public function bigInteger(string $column): self
    {
        return $this->column($column, 'BIGINT');
    }

    public function boolean(string $column): self
    {
        // SQLite has no boolean; an integer keeps the semantics identical.
        return $this->column(
            $column,
            $this->grammar instanceof SqliteGrammar ? 'INTEGER' : 'BOOLEAN',
        );
    }

    public function decimal(string $column, int $precision = 10, int $scale = 2): self
    {
        return $this->column($column, "DECIMAL({$precision}, {$scale})");
    }

    public function json(string $column): self
    {
        return $this->column(
            $column,
            $this->grammar instanceof SqliteGrammar ? 'TEXT' : 'JSON',
        );
    }

    public function timestamp(string $column): self
    {
        return $this->column($column, 'TIMESTAMP');
    }

    /**
     * The conventional created_at / updated_at pair.
     */
    public function timestamps(): self
    {
        return $this->timestamp('created_at')->nullable()
            ->timestamp('updated_at')->nullable();
    }

    /**
     * A nullable deleted_at column, for soft deletes.
     */
    public function softDeletes(): self
    {
        return $this->timestamp('deleted_at')->nullable();
    }

    private function column(string $name, string $type): self
    {
        $this->columns[] = $this->grammar->wrap($name) . ' ' . $type . ' NOT NULL';

        return $this;
    }

    /* --------------------------------------------------------- modifiers */

    /**
     * Make the most recently added column nullable.
     */
    public function nullable(): self
    {
        $index = count($this->columns) - 1;

        if ($index >= 0) {
            $this->columns[$index] = str_replace(' NOT NULL', ' NULL', $this->columns[$index]);
        }

        return $this;
    }

    /**
     * Give the most recently added column a default.
     *
     * Scalars are rendered inline because DDL cannot take bound parameters;
     * they are therefore restricted to safe literal types.
     */
    public function default(string|int|float|bool|null $value): self
    {
        $index = count($this->columns) - 1;

        if ($index < 0) {
            return $this;
        }

        $literal = match (true) {
            $value === null  => 'NULL',
            is_bool($value)  => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            // Single quotes doubled: the only escaping SQL string literals need.
            default => "'" . str_replace("'", "''", $value) . "'",
        };

        $this->columns[$index] .= ' DEFAULT ' . $literal;

        return $this;
    }

    public function unique(string ...$columns): self
    {
        $this->indexes[] = ['unique' => true, 'columns' => array_values($columns)];

        return $this;
    }

    public function index(string ...$columns): self
    {
        $this->indexes[] = ['unique' => false, 'columns' => array_values($columns)];

        return $this;
    }

    /**
     * A foreign key with an explicit delete rule.
     *
     * The rule is required rather than defaulted: silently choosing RESTRICT or
     * CASCADE on the caller's behalf is how orphaned rows and surprise deletions
     * both happen.
     */
    public function foreign(string $column, string $referencesTable, string $referencesColumn = 'id', string $onDelete = 'RESTRICT'): self
    {
        $rule = strtoupper(trim($onDelete));

        if (!in_array($rule, ['CASCADE', 'RESTRICT', 'SET NULL', 'NO ACTION'], true)) {
            throw new \InvalidArgumentException("[{$onDelete}] is not a valid ON DELETE rule.");
        }

        $this->constraints[] = sprintf(
            'FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s',
            $this->grammar->wrap($column),
            $this->grammar->wrap($referencesTable),
            $this->grammar->wrap($referencesColumn),
            $rule,
        );

        return $this;
    }

    /**
     * @return list<string> The CREATE TABLE statement, then any index statements.
     */
    public function toCreateSql(): array
    {
        $body = [...$this->columns, ...$this->constraints];

        $statements = [
            'CREATE TABLE ' . $this->grammar->wrap($this->table) . ' (' . implode(', ', $body) . ')',
        ];

        foreach ($this->indexes as $position => $index) {
            $name = $this->table . '_' . implode('_', $index['columns']) . '_' . ($index['unique'] ? 'unique' : 'index');

            $statements[] = sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $index['unique'] ? 'UNIQUE ' : '',
                $this->grammar->wrap(substr($name, 0, 60)),
                $this->grammar->wrap($this->table),
                implode(', ', array_map($this->grammar->wrap(...), $index['columns'])),
            );
        }

        return $statements;
    }
}
