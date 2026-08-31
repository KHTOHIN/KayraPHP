<?php

declare(strict_types=1);

namespace Kayra\Exceptions;

use Throwable;

final class MethodNotAllowedHttpException extends HttpException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(
        public readonly array $allowed = [],
        string $message = 'Method Not Allowed',
        ?Throwable $previous = null,
    ) {
        // RFC 9110 requires an Allow header on every 405.
        parent::__construct(405, $message, ['Allow' => implode(', ', $allowed)], $previous);
    }
}
