<?php

declare(strict_types=1);

namespace Kayra\Database\Events;

/**
 * Before the delete. Cancel to keep the row.
 */
final class Deleting extends CancellableModelEvent
{
}
