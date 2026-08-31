<?php

declare(strict_types=1);

namespace Kayra\Tests\Unit;

use Fiber;
use Kayra\Container\Container;
use Kayra\Container\ExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScopedThing
{
    public static int $built = 0;

    public function __construct(public readonly int $serial)
    {
    }

    public static function make(): self
    {
        return new self(++self::$built);
    }
}

/**
 * The framework's central claim is that per-request state cannot leak between
 * concurrent requests in a long-running worker. These tests are what make that
 * claim checkable rather than aspirational.
 *
 * Fibers are used as the concurrency primitive: they drive the exact same
 * {@see ExecutionContext} partitioning that Swoole coroutines do, and they run
 * everywhere, so this executes in ordinary CI.
 */
#[CoversClass(ExecutionContext::class)]
#[CoversClass(Container::class)]
final class CoroutineSafetyTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        ScopedThing::$built = 0;

        $this->container = new Container();
        $this->container->scoped(ScopedThing::class, static fn (): ScopedThing => ScopedThing::make());
        $this->container->singleton('shared', static fn (): object => new \stdClass());
    }

    #[Test]
    public function the_root_context_has_a_stable_id(): void
    {
        $this->assertSame(ExecutionContext::ROOT, ExecutionContext::id());
        $this->assertSame(ExecutionContext::id(), ExecutionContext::id());
    }

    #[Test]
    public function each_fiber_gets_its_own_context_id(): void
    {
        $ids = [];

        foreach (range(1, 3) as $ignored) {
            $fiber = new Fiber(function () use (&$ids): void {
                $ids[] = ExecutionContext::id();
            });
            $fiber->start();
        }

        $this->assertCount(3, array_unique($ids), 'Each fiber must have a distinct context id.');
        $this->assertNotContains(ExecutionContext::ROOT, $ids);
    }

    #[Test]
    public function concurrent_contexts_do_not_share_scoped_instances(): void
    {
        // This is the leak the framework exists to prevent: request B must never
        // receive the object built for request A.
        $serials = [];

        $a = new Fiber(function () use (&$serials): void {
            $serials['a1'] = $this->container->get(ScopedThing::class)->serial;
            Fiber::suspend();                       // interleave, mid-request
            $serials['a2'] = $this->container->get(ScopedThing::class)->serial;
        });

        $b = new Fiber(function () use (&$serials): void {
            $serials['b1'] = $this->container->get(ScopedThing::class)->serial;
            Fiber::suspend();
            $serials['b2'] = $this->container->get(ScopedThing::class)->serial;
        });

        $a->start();
        $b->start();
        $a->resume();
        $b->resume();

        $this->assertSame($serials['a1'], $serials['a2'], 'Within one request, scoped must be stable.');
        $this->assertSame($serials['b1'], $serials['b2'], 'Within one request, scoped must be stable.');
        $this->assertNotSame($serials['a1'], $serials['b1'], 'Two requests must not share a scoped instance.');
    }

    #[Test]
    public function terminating_one_request_does_not_destroy_another_in_flight(): void
    {
        // Before the fix, forgetScoped() cleared a flat array, so a request
        // finishing wiped the scoped state of every request still running.
        $observed = [];

        $long = new Fiber(function () use (&$observed): void {
            $observed['long_before'] = $this->container->get(ScopedThing::class)->serial;
            Fiber::suspend();
            $observed['long_after'] = $this->container->get(ScopedThing::class)->serial;
        });

        $short = new Fiber(function () use (&$observed): void {
            $observed['short'] = $this->container->get(ScopedThing::class)->serial;
            $this->container->forgetScoped();       // this request ends here
        });

        $long->start();
        $short->start();
        $long->resume();

        $this->assertSame(
            $observed['long_before'],
            $observed['long_after'],
            'A finished request destroyed a concurrent request\'s scoped state.',
        );
    }

    #[Test]
    public function forget_scoped_clears_only_the_calling_context(): void
    {
        $inFiber = null;

        $fiber = new Fiber(function () use (&$inFiber): void {
            $inFiber = $this->container->get(ScopedThing::class)->serial;
            Fiber::suspend();
        });
        $fiber->start();

        $root = $this->container->get(ScopedThing::class)->serial;

        $this->assertSame(2, $this->container->scopedContextCount());

        $this->container->forgetScoped();           // clears the root only

        $this->assertSame(1, $this->container->scopedContextCount());
        $this->assertNotSame($root, $this->container->get(ScopedThing::class)->serial);
    }

    #[Test]
    public function singletons_remain_shared_across_contexts(): void
    {
        // Singletons are application state and *should* cross contexts.
        $fromFiber = null;

        $fiber = new Fiber(function () use (&$fromFiber): void {
            $fromFiber = $this->container->get('shared');
        });
        $fiber->start();

        $this->assertSame($this->container->get('shared'), $fromFiber);
    }

    #[Test]
    public function build_stacks_do_not_bleed_between_contexts(): void
    {
        // Two contexts resolving overlapping graphs concurrently must not see
        // one another's frames, or the container reports a phantom cycle.
        $errors = [];

        $make = fn (): Fiber => new Fiber(function () use (&$errors): void {
            try {
                for ($i = 0; $i < 5; $i++) {
                    $this->container->get(ScopedThing::class);
                    Fiber::suspend();
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        });

        $fibers = [$make(), $make(), $make()];

        foreach ($fibers as $fiber) {
            $fiber->start();
        }

        for ($round = 0; $round < 5; $round++) {
            foreach ($fibers as $fiber) {
                if (!$fiber->isTerminated()) {
                    $fiber->resume();
                }
            }
        }

        $this->assertSame([], $errors, 'Interleaved resolution produced errors.');
    }

    #[Test]
    public function finished_contexts_do_not_accumulate(): void
    {
        // A context that resolves and then terminates must leave nothing behind,
        // or a long-running worker leaks memory one request at a time.
        for ($i = 0; $i < 50; $i++) {
            $fiber = new Fiber(function (): void {
                $this->container->get(ScopedThing::class);
                $this->container->forgetScoped();
            });
            $fiber->start();
        }

        $this->assertSame(0, $this->container->scopedContextCount());
    }
}
