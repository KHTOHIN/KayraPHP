<?php

declare(strict_types=1);

namespace Kayra\Auth\Access;

/**
 * The outcome of an authorization check, with its reasoning.
 *
 * A bare boolean loses the *why*, and the why is what turns "403 Forbidden"
 * into "you must verify your email address first".
 */
final class Response
{
    private function __construct(
        private readonly bool $allowed,
        private readonly string $message = '',
        private readonly int $status = 403,
    ) {
    }

    public static function allow(string $message = ''): self
    {
        return new self(true, $message);
    }

    /**
     * @param int $status 403 by default; 404 hides the resource's existence.
     */
    public static function deny(string $message = 'This action is unauthorized.', int $status = 403): self
    {
        return new self(false, $message, $status);
    }

    /**
     * Deny by pretending the record is not there.
     *
     * Use when the mere existence of a record is itself privileged — a 403 on
     * /invoices/512 confirms that invoice 512 exists.
     */
    public static function denyAsNotFound(string $message = 'Not Found.'): self
    {
        return new self(false, $message, 404);
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    public function denied(): bool
    {
        return !$this->allowed;
    }

    public function message(): string
    {
        return $this->message === '' ? 'This action is unauthorized.' : $this->message;
    }

    public function status(): int
    {
        return $this->status;
    }
}
