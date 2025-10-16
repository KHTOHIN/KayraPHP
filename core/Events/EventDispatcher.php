<?php

namespace Kayra\Events;

class EventDispatcher
{
    protected array $listeners = [];

    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(string $event, $payload = null): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($payload);
        }
        // For async: Dispatch to Swoole task worker
    }
}