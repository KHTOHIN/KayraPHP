<?php

declare(strict_types=1);

namespace Kayra\Database;

use InvalidArgumentException;

/**
 * Dialect-specific SQL rendering.
 *
 * The only part of the query builder that differs per database, isolated here
 * so adding a driver does not mean touching query construction.
 *
 * Identifier quoting lives here too, and it is a security boundary rather than
 * a formatting nicety: table and column names cannot be bound as parameters, so
 * they are validated against a strict pattern and then quoted. Anything that is
 * not a plain identifier is rejected outright instead of being escaped and
 * hoped for.
 */
abstract class Grammar
{
    /**
     * A bare identifier: letters, digits and underscore, not starting with a digit.
     */
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    abstract protected function openQuote(): string;

    abstract protected function closeQuote(): string;

    public static function for(string $driver): self
    {
        return match ($driver) {
            'mysql', 'mariadb' => new MySqlGrammar(),
            'pgsql', 'postgres', 'postgresql' => new PostgresGrammar(),
            'sqlite', 'sqlite3' => new SqliteGrammar(),
            'sqlsrv', 'mssql' => new SqlServerGrammar(),
            default => throw new InvalidArgumentException("Unsupported database driver [{$driver}]."),
        };
    }

    /**
     * Quote a possibly-qualified identifier such as `users` or `users.id`.
     *
     * @throws InvalidArgumentException When the identifier is not a plain name.
     */
    public function wrap(string $identifier): string
    {
        // "column AS alias"
        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $identifier, $matches) === 1) {
            return $this->wrap(trim($matches[1])) . ' as ' . $this->wrap(trim($matches[2]));
        }

        $parts = explode('.', $identifier);

        return implode('.', array_map($this->wrapSegment(...), $parts));
    }

    private function wrapSegment(string $segment): string
    {
        $segment = trim($segment);

        // '*' is the one non-identifier we accept, and only whole.
        if ($segment === '*') {
            return '*';
        }

        if (preg_match(self::IDENTIFIER, $segment) !== 1) {
            throw new InvalidArgumentException(
                "[{$segment}] is not a valid SQL identifier. "
                . 'Identifiers cannot be bound as parameters, so only plain names are accepted; '
                . 'use a bound value instead of interpolating input here.',
            );
        }

        return $this->openQuote() . $segment . $this->closeQuote();
    }

    /**
     * Validate a sort direction against an allow-list.
     */
    public function direction(string $direction): string
    {
        $normalised = strtoupper(trim($direction));

        if (!in_array($normalised, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException("[{$direction}] is not a valid sort direction.");
        }

        return $normalised;
    }

    /**
     * Validate a comparison operator against an allow-list.
     */
    public function operator(string $operator): string
    {
        $normalised = strtoupper(trim($operator));

        $allowed = [
            '=', '<', '>', '<=', '>=', '<>', '!=', '<=>',
            'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE',
            'IN', 'NOT IN', 'IS', 'IS NOT', 'BETWEEN', 'NOT BETWEEN',
            'REGEXP', 'NOT REGEXP', '~', '~*', '!~', '!~*',
        ];

        if (!in_array($normalised, $allowed, true)) {
            throw new InvalidArgumentException("[{$operator}] is not a supported SQL operator.");
        }

        // Preserve the caller's case for symbol operators.
        return in_array($normalised, ['=', '<', '>', '<=', '>=', '<>', '!=', '<=>', '~', '~*', '!~', '!~*'], true)
            ? $normalised
            : $normalised;
    }

    /**
     * Render LIMIT/OFFSET. SQL Server needs a different construction.
     */
    public function compileLimit(?int $limit, ?int $offset): string
    {
        $sql = '';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit);
        }

        if ($offset !== null) {
            $sql .= ' OFFSET ' . max(0, $offset);
        }

        return $sql;
    }

    /**
     * Whether this dialect supports `INSERT ... RETURNING`.
     */
    public function supportsReturning(): bool
    {
        return false;
    }
}
