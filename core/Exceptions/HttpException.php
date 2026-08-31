<?php

declare(strict_types=1);

namespace Kayra\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An exception that carries an HTTP status code.
 *
 * The exception handler renders these as their status instead of a 500, so
 * application code can signal HTTP outcomes by throwing.
 */
class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $headers Extra headers to attach to the response.
     */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        private readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
