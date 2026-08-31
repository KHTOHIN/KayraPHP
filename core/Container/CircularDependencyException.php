<?php

declare(strict_types=1);

namespace Kayra\Container;

/**
 * Thrown when a dependency cycle is detected while autowiring.
 */
final class CircularDependencyException extends ContainerException
{
    /**
     * @param list<string> $stack
     */
    public static function detected(string $id, array $stack): self
    {
        $cycle = implode(' -> ', [...$stack, $id]);

        return new self("Circular dependency detected while resolving [{$id}].\n  Cycle: {$cycle}");
    }
}
