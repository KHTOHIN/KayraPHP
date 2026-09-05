<?php

declare(strict_types=1);

namespace Kayra\Events;

use Kayra\Container\Container;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Decides which listeners an event reaches.
 *
 * Two things distinguish this from a plain array lookup:
 *
 *  - **Type matching, not name matching.** A listener registered for a parent
 *    class or an interface also receives every subtype. That is what makes a
 *    single listener on `ModelEvent` able to audit every write, rather than
 *    nine listeners on nine concrete classes.
 *  - **Deferred resolution.** A listener given as a class name is built from
 *    the container the first time its event actually fires, so registering a
 *    hundred listeners costs a hundred array entries and nothing else.
 *
 * Registrations are read-mostly configuration, so this is safe to share across
 * concurrent requests. Anything a listener needs *from* a request should be
 * resolved inside the listener, not captured at registration.
 */
final class ListenerProvider implements ListenerProviderInterface
{
    /**
     * Event type => list of registrations, in insertion order.
     *
     * @var array<string, list<array{listener: (callable(object): mixed)|string|array{0: string, 1: string}, priority: int, seq: int}>>
     */
    private array $listeners = [];

    /**
     * Resolved listener lists, keyed by concrete event class.
     *
     * Matching walks the class hierarchy, which is reflection-free but still
     * repeated work for an event dispatched in a loop.
     *
     * @var array<string, list<callable(object): mixed>>
     */
    private array $resolved = [];

    private int $sequence = 0;

    public function __construct(private readonly ?Container $container = null)
    {
    }

    /**
     * Register a listener for an event type.
     *
     * The listener may be a callable, an invokable class name, or a
     * [class, method] pair. The last two are resolved from the container when
     * the event fires.
     *
     * Higher priority runs first. Equal priorities keep registration order,
     * which is the only ordering anyone can reason about.
     *
     * A listener is only ever called with the event type it was registered for,
     * so it may declare that type in its signature rather than `object`. The
     * template is what lets static analysis see that.
     *
     * @template T of object
     *
     * @param class-string<T>                                             $event
     * @param (callable(T): mixed)|class-string|array{0: string, 1: string} $listener
     */
    public function listen(string $event, callable|string|array $listener, int $priority = 0): void
    {
        $this->listeners[$event][] = [
            'listener' => $listener,
            'priority' => $priority,
            'seq'      => $this->sequence++,
        ];

        // Any cached list may now be wrong: this event type could be a parent
        // of something already resolved.
        $this->resolved = [];
    }

    /**
     * Forget every listener for one event type, or all of them.
     *
     * @param class-string|null $event
     */
    public function forget(?string $event = null): void
    {
        if ($event === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$event]);
        }

        $this->resolved = [];
    }

    /**
     * Whether anything would run for this event type.
     *
     * Walks the same hierarchy dispatch does, so a listener on a parent counts.
     *
     * @param class-string $event
     */
    public function hasListeners(string $event): bool
    {
        if (isset($this->listeners[$event])) {
            return true;
        }

        foreach ([...array_values(class_parents($event) ?: []), ...array_values(class_implements($event) ?: [])] as $type) {
            if (isset($this->listeners[$type])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<callable(object): mixed>
     */
    public function getListenersForEvent(object $event): iterable
    {
        return $this->resolved[$event::class] ??= $this->build($event);
    }

    /**
     * @return list<callable(object): mixed>
     */
    private function build(object $event): array
    {
        $matches = [];

        // The event's own class, its parents, and every interface any of them
        // implement. class_parents/class_implements return the full chain, so
        // no manual walking is needed.
        $types = [
            $event::class,
            ...array_values(class_parents($event) ?: []),
            ...array_values(class_implements($event) ?: []),
        ];

        foreach ($types as $type) {
            foreach ($this->listeners[$type] ?? [] as $registration) {
                $matches[] = $registration;
            }
        }

        // Sort by priority, then by registration order. usort is not stable
        // across all inputs, so the sequence number does the tie-breaking.
        usort(
            $matches,
            static fn (array $a, array $b): int => $b['priority'] <=> $a['priority'] ?: $a['seq'] <=> $b['seq'],
        );

        return array_values(array_map(
            fn (array $r): callable => $this->toCallable($r['listener']),
            $matches,
        ));
    }

    /**
     * @param (callable(object): mixed)|string|array{0: string, 1: string} $listener
     *
     * @return callable(object): mixed
     */
    private function toCallable(callable|string|array $listener): callable
    {
        if (is_callable($listener) && !is_string($listener)) {
            return $listener;
        }

        // A class name or [class, method]: build it now, on first dispatch.
        return function (object $event) use ($listener): mixed {
            [$class, $method] = is_array($listener) ? $listener : [$listener, '__invoke'];

            if (is_callable($listener)) {
                // A plain function name, not a class.
                return $listener($event);
            }

            $instance = $this->container?->get($class) ?? new $class();

            return $instance->{$method}($event);
        };
    }
}
