<?php

declare(strict_types=1);

namespace Kayra\Runtime;

use Psr\Http\Server\RequestHandlerInterface;

/**
 * A way of feeding requests to the HTTP kernel.
 *
 * Separating this from the kernel is what lets the same application run under
 * php-fpm, Swoole or RoadRunner without conditional code: the kernel only ever
 * sees "a request in, a response out".
 */
interface RuntimeInterface
{
    /**
     * Whether this runtime can run in the current process.
     */
    public static function isAvailable(): bool;

    /**
     * Human-readable name, shown by `kayra doctor`.
     */
    public function name(): string;

    /**
     * Whether the process serves more than one request.
     *
     * Long-running runtimes require per-request state to be scoped, which the
     * container enforces through {@see \Kayra\Container\Scope::Scoped}.
     */
    public function isLongRunning(): bool;

    /**
     * Serve requests. Returns when the runtime stops.
     */
    public function run(RequestHandlerInterface $kernel): void;
}
