<?php

namespace Kayra\Database;

class QueryBuilder
{
    protected PDO $pdo;
    protected string $table;
    protected array $select = ['*'];
    protected array $where = [];
    protected array $orderBy = [];
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected string $type = 'select';

    public function __construct(PDO $pdo, string $table)
    {
        $this->pdo = $pdo;
        $this->table = $table;
    }

    public static function table(string $table, PDO $pdo = null): self
    {
        return new self($pdo ?? container('db')->getConnection(), $table);
    }

    public function select(array|string $columns): self
    {
        $this->select = is_array($columns) ? $columns : func_get_args();
        return $this;
    }

    public function where(string $column, string $operator, $value): self
    {
        $this->where[] = compact('column', 'operator', 'value');
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = compact('column', 'direction');
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function get(): array
    {
        $sql = "SELECT " . implode(', ', $this->select) . " FROM {$this->table}";
        $params = [];
        if ($this->where) {
            $whereClause = implode(' AND ', array_map(fn($w) => "{$w['column']} {$w['operator']} ?", $this->where));
            $sql .= " WHERE $whereClause";
            $params = array_column($this->where, 'value');
        }
        if ($this->orderBy) {
            $order = implode(', ', array_map(fn($o) => "{$o['column']} {$o['direction']}", $this->orderBy));
            $sql .= " ORDER BY $order";
        }
        if ($this->limit) $sql .= " LIMIT {$this->limit}";
        if ($this->offset) $sql .= " OFFSET {$this->offset}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
        // Lazy execution: Stmt is prepared but not executed until get/first
    }

    public function chunk(int $size, callable $callback): void
    {
        $page = 0;
        do {
            $results = $this->offset($page * $size)->limit($size)->get();
            $callback($results);
            $page++;
        } while (count($results) === $size);
        // For large sets, use cursor/streaming in ultra mode (Swoole coroutine fetch)
    }

    public function first(): ?array
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    // Insert/update stubs
    public function insert(array $data): int
    {
        $this->type = 'insert';
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
        return $this->pdo->lastInsertId();
    }

    public function update(array $data): int
    {
        $this->type = 'update';
        $setClause = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
        $sql = "UPDATE {$this->table} SET {$setClause}";
        $params = array_values($data);
        if ($this->where) {
            $whereClause = implode(' AND ', array_map(fn($w) => "{$w['column']} {$w['operator']} ?", $this->where));
            $sql .= " WHERE $whereClause";
            $params = array_merge($params, array_column($this->where, 'value'));
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    // Lazy: Build SQL but defer execution until get/first
    protected function buildSql(): string
    {
        // Implementation as in get(), but return SQL string for lazy prep
        return ''; // Stub for advanced lazy
    }
}