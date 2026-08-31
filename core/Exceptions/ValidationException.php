<?php

declare(strict_types=1);

namespace Kayra\Exceptions;

use Throwable;

/**
 * Thrown when request input fails validation.
 */
final class ValidationException extends HttpException
{
    /**
     * @param array<string, list<string>> $errors Field name => list of messages.
     */
    public function __construct(
        public readonly array $errors,
        string $message = 'The given data was invalid.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(422, $message, [], $previous);
    }
}
