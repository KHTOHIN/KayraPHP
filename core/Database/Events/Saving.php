<?php

declare(strict_types=1);

namespace Kayra\Database\Events;

/**
 * Before an insert or an update. Cancel to abandon both.
 */
final class Saving extends CancellableModelEvent
{
}
