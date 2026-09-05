<?php

declare(strict_types=1);

namespace Kayra\Database\Events;

use Kayra\Events\Stoppable;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * A "before" event a listener can veto.
 *
 * Calling {@see Stoppable::stopPropagation()} does two things here: later
 * listeners are skipped, and the write itself is abandoned. The model reads the
 * stopped flag as "no", so a listener does not need a second mechanism to
 * refuse -- and cannot accidentally allow a write by forgetting to return.
 */
abstract class CancellableModelEvent extends ModelEvent implements StoppableEventInterface
{
    use Stoppable;

    /**
     * Refuse the write. Reads better than stopPropagation() at a call site
     * whose intent is "do not save this".
     */
    public function cancel(): void
    {
        $this->stopPropagation();
    }

    public function isCancelled(): bool
    {
        return $this->isPropagationStopped();
    }
}
