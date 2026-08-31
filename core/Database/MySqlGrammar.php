<?php

declare(strict_types=1);

namespace Kayra\Database;

final class MySqlGrammar extends Grammar
{
    protected function openQuote(): string
    {
        return '`';
    }

    protected function closeQuote(): string
    {
        return '`';
    }

    /**
     * MySQL requires a LIMIT before OFFSET; the maximum row count stands in
     * when only an offset was given.
     */
    public function compileLimit(?int $limit, ?int $offset): string
    {
        if ($offset !== null && $limit === null) {
            return ' LIMIT 18446744073709551615 OFFSET ' . max(0, $offset);
        }

        return parent::compileLimit($limit, $offset);
    }
}
