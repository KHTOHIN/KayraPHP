<?php

declare(strict_types=1);

namespace Kayra\View;

use Kayra\Auth\Access\Gate;

/**
 * The rendering state belonging to one request.
 *
 * Everything here is written during a render and meaningless outside it:
 * captured sections, the layout chain, loop counters, the request's CSRF token
 * and CSP nonce, and the gate that answers @can.
 *
 * It lives apart from {@see Factory} because the factory is a singleton. Under
 * php-fpm that distinction is invisible — one request per process — but under
 * Swoole or FrankenPHP the factory is shared by every request in the worker,
 * and two concurrent renders writing to one section table would splice each
 * other's markup together. Worse, a cached gate would answer @can for whoever
 * happened to log in first.
 *
 * So the factory keeps one of these per execution context and throws it away
 * when the request ends.
 *
 * @internal
 */
final class RenderState
{
    /** @var array<string, string> Section name => rendered content. */
    public array $sections = [];

    /** @var list<string> Names of sections currently being captured. */
    public array $sectionStack = [];

    /** @var list<LoopState> */
    public array $loopStack = [];

    /** Parent template recorded by @extends. */
    public ?string $parent = null;

    /** Nesting depth of the current render. */
    public int $depth = 0;

    /** @var array<string, mixed> Values shared with every view in this request. */
    public array $shared = [];

    /** Resolved on first @can, then reused for the rest of the request. */
    public ?Gate $gate = null;

    /** Whether the gate resolver has already run, successfully or not. */
    public bool $gateResolved = false;

    /**
     * Whether this state is worth keeping between renders.
     *
     * A context that only ever rendered complete, balanced templates and shared
     * nothing has no state left to carry, so the factory can drop it instead of
     * holding an empty object for every coroutine that ever ran.
     */
    public function isEmpty(): bool
    {
        return $this->sections === []
            && $this->sectionStack === []
            && $this->loopStack === []
            && $this->parent === null
            && $this->depth === 0
            && $this->shared === []
            && $this->gate === null
            && !$this->gateResolved;
    }
}
