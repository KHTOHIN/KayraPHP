<?php

declare(strict_types=1);

namespace Kayra\Database;

final class SqliteGrammar extends Grammar
{
    protected function openQuote(): string
    {
        return '"';
    }

    protected function closeQuote(): string
    {
        return '"';
    }

    public function supportsReturning(): bool
    {
        // SQLite gained RETURNING in 3.35.
        return true;
    }
}
