<?php

declare(strict_types=1);

namespace Kayra\Container;

use Fiber;

/**
 * Identifies the current unit of concurrent execution.
 *
 * Under php-fpm there is exactly one, so this always returns the same id and
 * costs a single boolean check. Under Swoole every request runs in its own
 * coroutine, and under a Fiber-based runtime every request runs in its own
 * fiber — in both cases the container must keep per-request state separated by
 * this id, or concurrent requests read and destroy each other's services.
 *
 * This is what makes {@see Scope::Scoped} correct rather than merely intended.
 */
final class ExecutionContext
{
    public const ROOT = 'root';

    /**
     * Resolved once: whether a coroutine scheduler is present at all.
     *
     * Checked eagerly so the hot path is a property read, not a class_exists().
     */
    private static ?bool $hasCoroutines = null;

    /**
     * The id of the current coroutine, fiber, or the root context.
     */
    public static function id(): string
    {
        self::$hasCoroutines ??= class_exists(\Swoole\Coroutine::class, false)
            || class_exists(\OpenSwoole\Coroutine::class, false);

        if (self::$hasCoroutines) {
            $cid = self::coroutineId();

            if ($cid > 0) {
                return 'co' . $cid;
            }
        }

        // Fibers are the engine-level equivalent and are always available.
        $fiber = Fiber::getCurrent();

        if ($fiber !== null) {
            return 'fb' . spl_object_id($fiber);
        }

        return self::ROOT;
    }

    /**
     * Whether more than one request can be in flight in this process.
     */
    public static function isConcurrent(): bool
    {
        self::$hasCoroutines ??= class_exists(\Swoole\Coroutine::class, false)
            || class_exists(\OpenSwoole\Coroutine::class, false);

        return self::$hasCoroutines || Fiber::getCurrent() !== null;
    }

    private static function coroutineId(): int
    {
        if (class_exists(\Swoole\Coroutine::class, false)) {
            /** @var int|false $cid */
            $cid = \Swoole\Coroutine::getCid();

            return is_int($cid) ? $cid : -1;
        }

        if (class_exists(\OpenSwoole\Coroutine::class, false)) {
            /** @var int|false $cid */
            $cid = \OpenSwoole\Coroutine::getCid();

            return is_int($cid) ? $cid : -1;
        }

        return -1;
    }

    /**
     * @internal Test seam: forget the cached scheduler probe.
     */
    public static function reset(): void
    {
        self::$hasCoroutines = null;
    }
}
