<?php

declare(strict_types=1);

namespace Kayra\Auth\Access;

use Kayra\Exceptions\HttpException;
use Throwable;

/**
 * An action was denied.
 *
 * Carries the status the policy chose, so a policy that prefers to hide a
 * record's existence can answer 404 instead of 403.
 */
final class AuthorizationException extends HttpException
{
    public function __construct(
        string $message = 'This action is unauthorized.',
        int $status = 403,
        ?Throwable $previous = null,
    ) {
        parent::__construct($status, $message, [], $previous);
    }
}
