<?php

declare(strict_types=1);

namespace Kayra\Routing;

/**
 * Marker handler for `Route::view()` — a route that only renders a template.
 */
final class ViewRoute
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $view,
        public readonly array $data = [],
    ) {
    }
}
