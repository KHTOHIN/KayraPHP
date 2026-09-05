<?php

declare(strict_types=1);

namespace Kayra\Container;

use Closure;

/**
 * Fluent builder for contextual bindings.
 *
 * <code>
 * $container->when(ReportController::class)
 *           ->needs(FilesystemInterface::class)
 *           ->give(S3Filesystem::class);
 *
 * $container->when(HttpClient::class)
 *           ->needs('$timeout')
 *           ->give(fn () => 5.0);
 * </code>
 */
final class ContextualBindingBuilder
{
    private string $need = '';

    public function __construct(
        private readonly Container $container,
        private readonly string $concrete,
    ) {
    }

    /**
     * @param string $need A class/interface name, or '$parameterName'.
     */
    public function needs(string $need): self
    {
        $this->need = $need;

        return $this;
    }

    /**
     * @param (Closure(Container): mixed)|string $give A container id, or a factory.
     */
    public function give(Closure|string $give): void
    {
        if ($this->need === '') {
            throw new ContainerException('Call needs() before give() on a contextual binding.');
        }

        $this->container->addContextualBinding($this->concrete, $this->need, $give);
    }

    /**
     * Give the value of a configuration key.
     */
    public function giveConfig(string $key, mixed $default = null): void
    {
        $this->give(static fn (Container $c): mixed => $c->get(\Kayra\Config\Repository::class)->get($key, $default));
    }

    /**
     * Give every service registered under a tag.
     */
    public function giveTagged(string $tag): void
    {
        $this->give(static fn (Container $c): array => $c->tagged($tag));
    }
}
