<?php

declare(strict_types=1);

namespace Kayra\Database;

use RuntimeException;

/**
 * An attribute was mass-assigned that the model does not allow.
 *
 * Thrown rather than silently dropped: a discarded attribute looks like a
 * successful save right up until the data turns out to be missing.
 */
final class MassAssignmentException extends RuntimeException
{
}
