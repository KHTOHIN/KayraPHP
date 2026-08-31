<?php

declare(strict_types=1);

namespace Kayra\Container;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Thrown when the container has no entry for the requested identifier.
 */
final class NotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}
