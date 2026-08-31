<?php

declare(strict_types=1);

namespace Kayra\View;

/**
 * The `$loop` variable available inside `@foreach`.
 *
 * The derived values are property hooks (PHP 8.4), so they are computed on read
 * instead of being recalculated and stored on every iteration.
 */
final class LoopState
{
    /** 1-based position in the loop. */
    public int $iteration = 0;

    /** 0-based position in the loop. */
    public int $index {
        get => $this->iteration - 1;
    }

    public bool $first {
        get => $this->iteration === 1;
    }

    public bool $last {
        get => $this->count > 0 && $this->iteration === $this->count;
    }

    public bool $even {
        get => $this->iteration % 2 === 0;
    }

    public bool $odd {
        get => $this->iteration % 2 === 1;
    }

    /** Iterations still to come, when the total is known. */
    public int $remaining {
        get => max(0, $this->count - $this->iteration);
    }

    public int $depth {
        get => $this->parent === null ? 1 : $this->parent->depth + 1;
    }

    public function __construct(
        public readonly int $count = 0,
        public readonly ?LoopState $parent = null,
    ) {
    }

    public function advance(): void
    {
        $this->iteration++;
    }
}
