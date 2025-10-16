<?php

namespace App\Models;

use Kayra\Database\QueryBuilder;

class User
{
    protected string $table = 'users';

    public function all(): array
    {
        return QueryBuilder::table($this->table)->get();
    }

    public function find(int $id): ?array
    {
        return QueryBuilder::table($this->table)->where('id', '=', $id)->first();
    }

    public function create(array $data): int
    {
        return QueryBuilder::table($this->table)->insert($data);
    }
}