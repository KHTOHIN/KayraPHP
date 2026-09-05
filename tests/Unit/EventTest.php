<?php

declare(strict_types=1);

namespace Kayra\Tests\Unit;

use Kayra\Container\Container;
use Kayra\Events\Dispatcher;
use Kayra\Events\ListenerProvider;
use Kayra\Events\Stoppable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\StoppableEventInterface;

interface Auditable
{
}

class BaseEvent
{
    /** @var list<string> */
    public array $seen = [];
}

class ChildEvent extends BaseEvent implements Auditable
{
}

final class HaltingEvent extends BaseEvent implements StoppableEventInterface
{
    use Stoppable;
}

final class InvokableListener
{
    public function __invoke(BaseEvent $event): void
    {
        $event->seen[] = 'invokable';
    }
}

final class MethodListener
{
    public function handle(BaseEvent $event): void
    {
        $event->seen[] = 'method';
    }
}

final class CountingListener
{
    public static int $built = 0;

    public function __construct()
    {
        self::$built++;
    }

    public function __invoke(BaseEvent $event): void
    {
        $event->seen[] = 'counted';
    }
}

#[CoversClass(Dispatcher::class)]
#[CoversClass(ListenerProvider::class)]
final class EventTest extends TestCase
{
    private ListenerProvider $listeners;

    private Dispatcher $events;

    protected function setUp(): void
    {
        $this->listeners = new ListenerProvider(new Container());
        $this->events = new Dispatcher($this->listeners);
        CountingListener::$built = 0;
    }

    /* --------------------------------------------------------------------
     | Dispatch
     * -------------------------------------------------------------------- */

    #[Test]
    public function a_closure_listener_receives_the_event(): void
    {
        $this->events->listen(BaseEvent::class, static function (BaseEvent $e): void {
            $e->seen[] = 'closure';
        });

        $this->assertSame(['closure'], $this->events->dispatch(new BaseEvent())->seen);
    }

    #[Test]
    public function the_same_object_comes_back(): void
    {
        // A listener that mutated the event has said something, and the caller
        // reads it off the object it passed in.
        $event = new BaseEvent();

        $this->assertSame($event, $this->events->dispatch($event));
    }

    #[Test]
    public function an_event_with_no_listeners_is_harmless(): void
    {
        $this->assertSame([], $this->events->dispatch(new BaseEvent())->seen);
    }

    /* --------------------------------------------------------------------
     | Type matching
     * -------------------------------------------------------------------- */

    #[Test]
    public function a_listener_on_a_parent_class_receives_the_subtype(): void
    {
        // The reason ModelEvent has subclasses: one audit listener, not nine.
        $this->events->listen(BaseEvent::class, static fn (BaseEvent $e) => $e->seen[] = 'parent');

        $this->assertSame(['parent'], $this->events->dispatch(new ChildEvent())->seen);
    }

    #[Test]
    public function a_listener_on_an_interface_receives_implementations(): void
    {
        // Typed as the interface it registered for, not as the concrete class:
        // anything implementing Auditable will arrive here, and only some of
        // those are BaseEvents.
        $received = null;

        $this->events->listen(Auditable::class, static function (Auditable $e) use (&$received): void {
            $received = $e;
        });

        $event = new ChildEvent();
        $this->events->dispatch($event);

        $this->assertSame($event, $received);
    }

    #[Test]
    public function a_listener_on_a_subtype_does_not_receive_the_parent(): void
    {
        $this->events->listen(ChildEvent::class, static fn (BaseEvent $e) => $e->seen[] = 'child');

        $this->assertSame([], $this->events->dispatch(new BaseEvent())->seen);
    }

    /* --------------------------------------------------------------------
     | Order
     * -------------------------------------------------------------------- */

    #[Test]
    public function higher_priority_runs_first(): void
    {
        $this->events->listen(BaseEvent::class, static fn (BaseEvent $e) => $e->seen[] = 'low', -10);
        $this->events->listen(BaseEvent::class, static fn (BaseEvent $e) => $e->seen[] = 'high', 10);
        $this->events->listen(BaseEvent::class, static fn (BaseEvent $e) => $e->seen[] = 'mid');

        $this->assertSame(['high', 'mid', 'low'], $this->events->dispatch(new BaseEvent())->seen);
    }

    #[Test]
    public function equal_priorities_keep_registration_order(): void
    {
        // The only tie-break anyone can reason about. usort is not stable, so
        // this would drift without an explicit sequence number.
        foreach (range(1, 8) as $i) {
            $this->events->listen(BaseEvent::class, static fn (BaseEvent $e) => $e->seen[] = (string) $i);
        }

        $this->assertSame(['1', '2', '3', '4', '5', '6', '7', '8'], $this->events->dispatch(new BaseEvent())->seen);
    }

    /* --------------------------------------------------------------------
     | Stopping
     * -------------------------------------------------------------------- */

    #[Test]
    public function a_stopped_event_skips_the_rest(): void
    {
        $this->events->listen(HaltingEvent::class, static function (HaltingEvent $e): void {
            $e->seen[] = 'first';
            $e->stopPropagation();
        });
        $this->events->listen(HaltingEvent::class, static fn (HaltingEvent $e) => $e->seen[] = 'second');

        $this->assertSame(['first'], $this->events->dispatch(new HaltingEvent())->seen);
    }

    #[Test]
    public function an_event_that_arrives_already_stopped_reaches_nobody(): void
    {
        // PSR-14 requires this, and it is what lets a caller pre-empt a
        // dispatch without unregistering anything.
        $this->events->listen(HaltingEvent::class, static fn (HaltingEvent $e) => $e->seen[] = 'ran');

        $event = new HaltingEvent();
        $event->stopPropagation();

        $this->assertSame([], $this->events->dispatch($event)->seen);
    }

    #[Test]
    public function a_non_stoppable_event_runs_every_listener(): void
    {
        $this->events->listen(BaseEvent::class, static fn (BaseEvent $e) => $e->seen[] = 'a');
        $this->events->listen(BaseEvent::class, static fn (BaseEvent $e) => $e->seen[] = 'b');

        $this->assertSame(['a', 'b'], $this->events->dispatch(new BaseEvent())->seen);
    }

    /* --------------------------------------------------------------------
     | Resolution
     * -------------------------------------------------------------------- */

    #[Test]
    public function an_invokable_class_name_is_resolved_from_the_container(): void
    {
        $this->events->listen(BaseEvent::class, InvokableListener::class);

        $this->assertSame(['invokable'], $this->events->dispatch(new BaseEvent())->seen);
    }

    #[Test]
    public function a_class_and_method_pair_is_resolved(): void
    {
        $this->events->listen(BaseEvent::class, [MethodListener::class, 'handle']);

        $this->assertSame(['method'], $this->events->dispatch(new BaseEvent())->seen);
    }

    #[Test]
    public function a_listener_class_is_not_built_until_its_event_fires(): void
    {
        // Registering a hundred listeners should cost a hundred array entries,
        // not a hundred objects.
        $this->events->listen(ChildEvent::class, CountingListener::class);

        $this->assertSame(0, CountingListener::$built, 'registration must not construct');

        $this->events->dispatch(new ChildEvent());

        $this->assertSame(1, CountingListener::$built);
    }

    #[Test]
    public function a_listener_that_throws_is_not_swallowed(): void
    {
        $this->events->listen(BaseEvent::class, static function (): never {
            throw new \RuntimeException('listener blew up');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('listener blew up');

        $this->events->dispatch(new BaseEvent());
    }

    /* --------------------------------------------------------------------
     | Registration bookkeeping
     * -------------------------------------------------------------------- */

    #[Test]
    public function has_listeners_follows_the_hierarchy(): void
    {
        $this->listeners->listen(BaseEvent::class, static fn (): null => null);

        $this->assertTrue($this->listeners->hasListeners(BaseEvent::class));
        // A subtype counts, because dispatching one would reach that listener.
        $this->assertTrue($this->listeners->hasListeners(ChildEvent::class));
        $this->assertTrue($this->listeners->hasListeners(HaltingEvent::class));
        // Something outside the hierarchy does not.
        $this->assertFalse($this->listeners->hasListeners(\stdClass::class));
    }

    #[Test]
    public function registering_after_a_dispatch_still_takes_effect(): void
    {
        // The resolved-listener cache must not outlive a registration, or a
        // listener added by a lazily booted provider would never run.
        $this->events->dispatch(new ChildEvent());

        $this->events->listen(BaseEvent::class, static fn (BaseEvent $e) => $e->seen[] = 'late');

        $this->assertSame(['late'], $this->events->dispatch(new ChildEvent())->seen);
    }

    #[Test]
    public function forget_removes_listeners(): void
    {
        $this->events->listen(BaseEvent::class, static fn (BaseEvent $e) => $e->seen[] = 'gone');
        $this->listeners->forget(BaseEvent::class);

        $this->assertSame([], $this->events->dispatch(new BaseEvent())->seen);
    }
}
