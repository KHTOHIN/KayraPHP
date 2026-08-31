<?php

declare(strict_types=1);

namespace Kayra\Database;

use Kayra\Config\Repository;
use InvalidArgumentException;

/**
 * Resolves and caches named connections.
 *
 * Connections are held for the application's lifetime rather than per request:
 * establishing one costs a TCP round trip and, for MySQL, an authentication
 * handshake, so reconnecting per request is the single most expensive thing a
 * database layer can do. They carry no request state, so sharing them is safe.
 */
final class DatabaseManager
{
    /** @var array<string, Connection> */
    private array $connections = [];

    public function __construct(private readonly Repository $config)
    {
    }

    public function connection(?string $name = null): Connection
    {
        $name ??= $this->config->string('database.default', 'sqlite');

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $config = $this->config->get("database.connections.{$name}");

        if (!is_array($config)) {
            throw new InvalidArgumentException("Database connection [{$name}] is not configured.");
        }

        /** @var array<string, mixed> $config */
        $driver = (string) ($config['driver'] ?? 'sqlite');

        return $this->connections[$name] = new Connection($config, Grammar::for($driver));
    }

    public function table(string $table, ?string $connection = null): QueryBuilder
    {
        return $this->connection($connection)->table($table);
    }

    /**
     * Close every connection.
     *
     * A long-running worker calls this when recycling, or after a fork.
     */
    public function disconnect(): void
    {
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }

        $this->connections = [];
    }

    /**
     * @return list<string>
     */
    public function connectionNames(): array
    {
        return array_keys($this->connections);
    }
}
