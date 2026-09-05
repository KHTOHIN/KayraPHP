<?php

declare(strict_types=1);

namespace Kayra\Database;

use Kayra\Exceptions\HttpException;

/**
 * No row matched.
 *
 * Extends HttpException with a 404 so that findOrFail() in a controller
 * produces an honest "not found" response instead of a 500.
 */
final class ModelNotFoundException extends HttpException
{
    public function __construct(string $message = 'Record not found.')
    {
        parent::__construct(404, $message);
    }
}
