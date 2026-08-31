<?php

declare(strict_types=1);

namespace Kayra\Database;

use InvalidArgumentException;

final class SqlServerGrammar extends Grammar
{
    protected function openQuote(): string
    {
        return '[';
    }

    protected function closeQuote(): string
    {
        return ']';
    }

    /**
     * SQL Server has no LIMIT; it uses OFFSET/FETCH, and that requires an
     * ORDER BY, which the query builder enforces before calling this.
     */
    public function compileLimit(?int $limit, ?int $offset): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }

        if ($limit !== null && $offset === null) {
            $offset = 0;
        }

        $sql = ' OFFSET ' . max(0, (int) $offset) . ' ROWS';

        if ($limit !== null) {
            $sql .= ' FETCH NEXT ' . max(0, $limit) . ' ROWS ONLY';
        }

        return $sql;
    }

    public function requiresOrderByForPaging(): bool
    {
        return true;
    }
}
