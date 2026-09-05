<?php

declare(strict_types=1);

namespace Kayra\Cache;

use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgument;

/**
 * A cache key the specification does not allow.
 *
 * Implements the PSR-16 marker so code written against the interface can catch
 * it without knowing this implementation exists.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements Psr16InvalidArgument
{
}
