<?php

namespace Kayra\Database;

use PDO;

class ConnectionPool
{
    protected array $connections = [];
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getConnection(string $name = null): PDO
    {
        $name = $name ?? $this->config['default'];
        if (!isset($this->connections[$name])) {
            $connConfig = $this->config['connections'][$name];
            $dsn = $this->buildDsn($connConfig);
            $this->connections[$name] = new PDO($dsn, $connConfig['username'] ?? '', $connConfig['password'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // Prepared statement cache stub
            $this->connections[$name]->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
        return $this->connections[$name];
        // TODO: For Swoole ultra mode, swap to coroutine pool (e.g., Swoole\Coroutine\MySQL)
    }

    protected function buildDsn(array $config): string
    {
        return match ($config['driver']) {
            'sqlite' => "sqlite:{$config['database']}",
            'mysql' => "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            default => throw new \Exception('Unsupported driver'),
        };
    }
}