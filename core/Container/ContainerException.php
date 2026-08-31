<?php

declare(strict_types=1);

namespace Kayra\Container;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

/**
 * Thrown when a service exists but cannot be built.
 */
class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
    /**
     * @param list<string> $stack The resolution stack, outermost first.
     */
    public static function forStack(string $message, array $stack): self
    {
        if ($stack === []) {
            return new self($message);
        }

        return new self($message . "\n  Resolution path: " . implode(' -> ', $stack));
    }
}
