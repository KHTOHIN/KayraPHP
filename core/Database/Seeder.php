<?php

namespace Kayra\Database;

class Seeder
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function run(): void
    {
        // Sample: Seed users (if not exist)
        $this->pdo->exec("INSERT OR IGNORE INTO users (name, email) VALUES ('Alice', 'alice@example.com')");
        // Extend for other seeders
    }
}