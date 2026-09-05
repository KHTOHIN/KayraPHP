<?php

declare(strict_types=1);

namespace Kayra\Auth;

use SensitiveParameter;

/**
 * How a request is associated with a user.
 *
 * One contract for both session and token authentication, so middleware,
 * controllers and policies never need to know which is in use.
 */
interface Guard
{
    public function check(): bool;

    public function guest(): bool;

    public function user(): ?Authenticatable;

    public function id(): int|string|null;

    /**
     * Verify credentials without establishing a session.
     *
     * @param array<string, mixed> $credentials
     */
    public function validate(#[SensitiveParameter] array $credentials): bool;
}
