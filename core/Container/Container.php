<?php

namespace Kayra\Container;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionParameter;
use Exception;

class Container implements ContainerInterface
{
    protected array $factories = [];
    protected array $instances = [];

    /**
     * Retrieve an entry from the container.
     */
    public function get(string $id)
    {
        // If already resolved (singleton)
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // If factory is defined, call it
        if (isset($this->factories[$id])) {
            $this->instances[$id] = $this->factories[$id]($this);
            return $this->instances[$id];
        }

        // Try auto-wiring (class auto-resolve)
        if (class_exists($id)) {
            $object = $this->build($id);
            $this->instances[$id] = $object;
            return $object;
        }

        throw new Exception("Service [{$id}] not found in container.");
    }

    /**
     * Check if a service is registered.
     */
    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->instances[$id]);
    }

    /**
     * Register a service factory (non-singleton).
     */
    public function bind(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    /**
     * Register a singleton service factory.
     */
    public function singleton(string $id, callable $factory): void
    {
        // Same as bind, but result cached automatically via get()
        $this->factories[$id] = $factory;
    }

    /**
     * Automatically build an object via reflection (auto-wiring).
     */
    protected function build(string $class): object
    {
        $reflector = new ReflectionClass($class);

        if (!$reflector->isInstantiable()) {
            throw new Exception("Class [{$class}] is not instantiable.");
        }

        $constructor = $reflector->getConstructor();

        // No constructor => simple new instance
        if (is_null($constructor)) {
            return new $class;
        }

        $dependencies = array_map(
            fn(ReflectionParameter $param) => $this->resolveParameter($param),
            $constructor->getParameters()
        );

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Resolve a constructor parameter dependency.
     */
    protected function resolveParameter(ReflectionParameter $param)
    {
        $type = $param->getType();

        if ($type && !$type->isBuiltin()) {
            $depClass = $type->getName();
            return $this->get($depClass);
        }

        // Default value if available
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        throw new Exception("Unresolvable dependency [{$param->getName()}]");
    }
}