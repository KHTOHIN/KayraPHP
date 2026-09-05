<?php

declare(strict_types=1);

namespace Kayra\Container;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Throwable;

/**
 * Flattens the service graph into a plain-array construction plan.
 *
 * At run time the container answers "how do I build X?" with reflection. That
 * answer never changes between requests, so it can be computed once at deploy
 * time and written to a file — which is what Spring Boot and ASP.NET Core do
 * at build time, and what `kayra optimize` does here.
 *
 * The plan is deliberately data, not generated code: plain nested arrays are
 * `var_export`-able, OPcache-friendly, and impossible to get wrong through
 * string concatenation.
 *
 * A class is left out of the plan (and so falls back to reflection) whenever
 * its construction genuinely depends on run-time state — see {@see plan()}.
 */
final class ContainerCompiler
{
    /** Resolve the parameter from the container. */
    public const KIND_SERVICE = 's';

    /** Use a literal default value. */
    public const KIND_VALUE = 'v';

    /** Pass null. */
    public const KIND_NULL = 'n';

    /**
     * A construction plan per class, or false for one that must stay dynamic.
     *
     * @var array<class-string, list<array{k: 's', i: class-string}|array{k: 'v', v: mixed}|array{k: 'n'}>|false>
     */
    private array $plans = [];

    /** @var list<string> */
    private array $skipped = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Build a construction plan reachable from the given root classes.
     *
     * @param list<string> $roots Classes the application is known to resolve.
     * @return array<class-string, list<array{k: 's', i: class-string}|array{k: 'v', v: mixed}|array{k: 'n'}>>
     */
    public function compile(array $roots): array
    {
        $this->plans = [];
        $this->skipped = [];

        /** @var list<class-string> $queue */
        $queue = array_values(array_unique(array_filter($roots, class_exists(...))));

        // Breadth-first over the dependency graph. Every service a root needs is
        // itself a root, transitively.
        while ($queue !== []) {
            $class = array_shift($queue);

            if (array_key_exists($class, $this->plans)) {
                continue;
            }

            $plan = $this->plan($class);
            $this->plans[$class] = $plan;

            if ($plan === false) {
                continue;
            }

            foreach ($plan as $parameter) {
                if ($parameter['k'] === self::KIND_SERVICE && !array_key_exists($parameter['i'], $this->plans)) {
                    $queue[] = $parameter['i'];
                }
            }
        }

        /** @var array<class-string, list<array{k: 's', i: class-string}|array{k: 'v', v: mixed}|array{k: 'n'}>> $compiled */
        $compiled = array_filter($this->plans, static fn (array|false $p): bool => $p !== false);

        // Deterministic order keeps the generated file diff-friendly.
        ksort($compiled);

        return $compiled;
    }

    /**
     * Classes that could not be compiled, with the reason.
     *
     * @return list<string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * Plan one class, or false when it must stay dynamic.
     *
     * @param class-string $class
     *
     * @return list<array{k: 's', i: class-string}|array{k: 'v', v: mixed}|array{k: 'n'}>|false
     */
    private function plan(string $class): array|false
    {
        if (!class_exists($class)) {
            return $this->skip($class, 'no such class');
        }

        try {
            $reflector = new ReflectionClass($class);
        } catch (Throwable) {
            return $this->skip($class, 'not reflectable');
        }

        if (!$reflector->isInstantiable()) {
            // Interfaces and abstracts are resolved through a binding, not built.
            return $this->skip($class, 'not instantiable');
        }

        // A contextual binding changes what this class receives depending on who
        // asked, which a static plan cannot express.
        if ($this->container->hasContextualBindings($class)) {
            return $this->skip($class, 'has contextual bindings');
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $plan = [];

        foreach ($constructor->getParameters() as $parameter) {
            $entry = $this->planParameter($parameter);

            if ($entry === null) {
                return $this->skip($class, "parameter \${$parameter->getName()} cannot be pre-resolved");
            }

            $plan[] = $entry;
        }

        return $plan;
    }

    /**
     * @return (array{k: 's', i: class-string}|array{k: 'v', v: mixed}|array{k: 'n'})|null Null when the parameter must stay dynamic.
     */
    private function planParameter(ReflectionParameter $parameter): ?array
    {
        // Variadics take whatever is passed at call time.
        if ($parameter->isVariadic()) {
            return null;
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $name = $type->getName();

            // self/static/parent resolve relative to the call site.
            if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
                return null;
            }

            $resolved = $this->container->resolveAlias($name);

            // The plan records which id to ask the container for, not whether it
            // is resolvable today. An interface with no binding yet is still a
            // valid entry: a provider may bind it after compilation, and if
            // nothing does, resolution fails at run time with its own clear error.
            if ($this->container->bound($resolved)
                || class_exists($resolved)
                || interface_exists($resolved)) {
                return ['k' => self::KIND_SERVICE, 'i' => $resolved];
            }

            if ($parameter->isDefaultValueAvailable()) {
                return $this->planDefault($parameter);
            }

            return $parameter->allowsNull() ? ['k' => self::KIND_NULL] : null;
        }

        // Union and intersection types need run-time trial resolution.
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return null;
        }

        // Builtin or untyped: only a literal default is safe to bake in.
        if ($parameter->isDefaultValueAvailable()) {
            return $this->planDefault($parameter);
        }

        return $parameter->allowsNull() ? ['k' => self::KIND_NULL] : null;
    }

    /**
     * @return (array{k: 'v', v: mixed}|array{k: 'n'})|null
     */
    private function planDefault(ReflectionParameter $parameter): ?array
    {
        try {
            $value = $parameter->getDefaultValue();
        } catch (Throwable) {
            return null;
        }

        // Objects and resources cannot be represented in an exported array.
        if (is_object($value) || is_resource($value)) {
            return null;
        }

        if (is_array($value) && !$this->isExportable($value)) {
            return null;
        }

        return ['k' => self::KIND_VALUE, 'v' => $value];
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function isExportable(array $value): bool
    {
        foreach ($value as $item) {
            if (is_object($item) || is_resource($item)) {
                return false;
            }

            if (is_array($item) && !$this->isExportable($item)) {
                return false;
            }
        }

        return true;
    }

    private function skip(string $class, string $reason): false
    {
        $this->skipped[] = "{$class} ({$reason})";

        return false;
    }
}
