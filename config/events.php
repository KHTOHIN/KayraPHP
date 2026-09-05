<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Listeners
    |--------------------------------------------------------------------------
    | Event class => one listener or a list of them. A listener may be an
    | invokable class name, a [class, method] pair, or any callable; the first
    | two are built from the container the first time the event actually fires.
    |
    | Listeners are matched on the class hierarchy, not the class name. A
    | listener registered for a base class or an interface receives every
    | subtype, which is how one listener can audit every model write:
    |
    |     Kayra\Database\Events\ModelEvent::class => [App\Listeners\AuditWrites::class],
    |
    | Register at runtime instead with $events->listen($event, $listener, $priority).
    */

    'listeners' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Model lifecycle events
    |--------------------------------------------------------------------------
    | Whether models announce retrieved / saving / creating / created /
    | updating / updated / saved / deleting / deleted.
    |
    | Turning this off is a real optimisation for a bulk import, but reach for
    | Model::withoutEvents() first: it scopes the silence to the code that wants
    | it instead of to the whole application.
    */

    'model_events' => env('MODEL_EVENTS', true),
];
