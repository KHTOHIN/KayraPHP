<?php

declare(strict_types=1);

namespace Kayra\Database\Events;

use Kayra\Database\Model;

/**
 * Base class for everything a model announces about its own lifecycle.
 *
 * Listening to this type catches all of them, because the listener provider
 * matches on the class hierarchy. That is the point of having a base class
 * rather than nine unrelated ones: an audit log registers once.
 *
 *     $events->listen(ModelEvent::class, AuditWrites::class);   // all of them
 *     $events->listen(Created::class, SendWelcomeEmail::class); // just one
 */
abstract class ModelEvent
{
    public function __construct(public readonly Model $model)
    {
    }
}
