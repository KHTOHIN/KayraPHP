<?php

declare(strict_types=1);

namespace Kayra\Events;

/**
 * The propagation half of {@see \Psr\EventDispatcher\StoppableEventInterface}.
 *
 * Use it on an event where a listener is allowed to have the last word -- a
 * "before" hook that can veto, an authorization check, a pipeline that should
 * stop at the first handler that recognises the input.
 *
 * A class using this trait must still declare the interface itself; a trait
 * cannot, and silently not implementing it would mean the dispatcher never
 * checks.
 */
trait Stoppable
{
    private bool $propagationStopped = false;

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * Stop here: no later listener runs, and the caller sees the event as
     * halted.
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}
