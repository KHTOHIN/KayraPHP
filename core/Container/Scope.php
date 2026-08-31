<?php

declare(strict_types=1);

namespace Kayra\Container;

/**
 * Lifetime of a container binding.
 *
 * The distinction between {@see self::Singleton} and {@see self::Scoped} is what
 * makes KayraPHP safe on long-running runtimes (Swoole, RoadRunner, FrankenPHP):
 * scoped services are discarded when a request ends, so per-request state can
 * never leak into the next request.
 */
enum Scope: string
{
    /** One instance for the entire application lifetime. */
    case Singleton = 'singleton';

    /** One instance per request; cleared by Container::forgetScoped(). */
    case Scoped = 'scoped';

    /** A fresh instance on every resolution. */
    case Transient = 'transient';
}
