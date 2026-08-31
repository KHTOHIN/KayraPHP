<?php

declare(strict_types=1);

namespace Kayra\Database;

final class PostgresGrammar extends Grammar
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
        return true;
    }
}
