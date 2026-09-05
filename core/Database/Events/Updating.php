<?php

declare(strict_types=1);

namespace Kayra\Database\Events;

/**
 * Before an update. Cancel to abandon it.
 */
final class Updating extends CancellableModelEvent
{
}
