<?php

declare(strict_types=1);

namespace Kayra\Database;

use RuntimeException;
use Throwable;

/**
 * A database failure.
 *
 * The SQL and bindings are carried for debugging but are never included in the
 * message: bindings hold user data, and the exception message may reach a log
 * or, in a misconfigured deployment, a response body.
 */
final class QueryException extends RuntimeException
{
    /**
     * @param list<mixed> $bindings
     */
    public function __construct(
        string $message,
        public readonly string $sql = '',
        public readonly array $bindings = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
