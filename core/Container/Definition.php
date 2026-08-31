<?php

declare(strict_types=1);

namespace Kayra\Container;

use Closure;

/**
 * An immutable description of how to build one container entry.
 */
final class Definition
{
    /**
     * @param string               $id       Abstract identifier (class, interface or string key).
     * @param Closure|string|null  $concrete Factory closure, concrete class name, or null to build $id itself.
     * @param list<string>         $tags
     */
    public function __construct(
        public string $id,
        public Closure|string|null $concrete = null,
        public Scope $scope = Scope::Transient,
        public bool $lazy = false,
        public array $tags = [],
    ) {
    }

    /**
     * The class name this definition ultimately builds, when statically known.
     */
    public function concreteClass(): ?string
    {
        if (is_string($this->concrete)) {
            return $this->concrete;
        }

        if ($this->concrete === null && class_exists($this->id)) {
            return $this->id;
        }

        return null;
    }
}
