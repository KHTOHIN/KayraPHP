<?php

declare(strict_types=1);

namespace Kayra\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * PSR-14 event dispatcher.
 *
 * Deliberately thin: it asks the provider who is listening, calls them in
 * order, and stops early if the event says to. Everything interesting about
 * *which* listeners run lives in {@see ListenerProvider}, which is the split
 * PSR-14 exists to enforce.
 *
 * A listener that throws stops the dispatch and the exception reaches the
 * caller. That is the specified behaviour, and the alternative -- swallowing it
 * -- turns a broken listener into a silent one.
 */
final class Dispatcher implements EventDispatcherInterface
{
    public function __construct(private readonly ListenerProvider $listeners)
    {
    }

    /**
     * Dispatch an event and return it.
     *
     * The same object comes back, so a listener that mutated it has said
     * something the caller can read -- which is how a "creating" event vetoes
     * the write about to happen.
     *
     * @template T of object
     *
     * @param T $event
     *
     * @return T
     */
    public function dispatch(object $event): object
    {
        $stoppable = $event instanceof StoppableEventInterface;

        // Checked before the first listener as well as between them: an event
        // may arrive already stopped, and PSR-14 requires that to be honoured.
        if ($stoppable && $event->isPropagationStopped()) {
            return $event;
        }

        foreach ($this->listeners->getListenersForEvent($event) as $listener) {
            $listener($event);

            if ($stoppable && $event->isPropagationStopped()) {
                break;
            }
        }

        return $event;
    }

    /**
     * The provider, for registering listeners at runtime.
     */
    public function listeners(): ListenerProvider
    {
        return $this->listeners;
    }

    /**
     * Convenience passthrough so applications rarely touch the provider.
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
        $this->listeners->listen($event, $listener, $priority);
    }
}
