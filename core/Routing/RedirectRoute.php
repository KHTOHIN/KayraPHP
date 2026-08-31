<?php

declare(strict_types=1);

namespace Kayra\Routing;

/**
 * Marker handler for `Route::redirect()`.
 */
final class RedirectRoute
{
    public function __construct(
        public readonly string $to,
        public readonly int $status = 302,
    ) {
    }
}
