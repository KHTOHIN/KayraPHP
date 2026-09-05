<?php

declare(strict_types=1);

namespace Kayra\Database\Events;

/**
 * Before the row exists. Cancel to abandon the insert.
 */
final class Creating extends CancellableModelEvent
{
}
