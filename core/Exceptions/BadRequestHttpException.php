<?php

declare(strict_types=1);

namespace Kayra\Exceptions;

use Throwable;

final class BadRequestHttpException extends HttpException
{
    public function __construct(string $message = 'Bad Request', ?Throwable $previous = null)
    {
        parent::__construct(400, $message, [], $previous);
    }
}
