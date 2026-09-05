<?php

declare(strict_types=1);

namespace Kayra\Container;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Throwable;

/**
 * The KayraPHP service container.
 *
 * Design goals, in priority order:
 *
 *  1. Correct lifetimes. {@see Scope::Scoped} entries are dropped between requests
 *     so the container is safe under Swoole / RoadRunner / FrankenPHP.
 *  2. Cheap in production. Every reflection lookup is cached, and the whole graph
 *     can be flattened to plain PHP by {@see Compiler}.
 *  3. Explicit. No magic string resolution, no silent nulls.
 */
class Container implements ContainerInterface
{
    /** @var array<string, Definition> */
    protected array $definitions = [];

    /** @var array<string, mixed> */
    protected array $singletons = [];

    /**
     * Request-scoped instances, partitioned by execution context.
     *
     * Under a coroutine runtime several requests share one container, so a flat
     * array here would let concurrent requests see and destroy each other's
     * services. Keying by {@see ExecutionContext::id()} is what actually keeps
     * {@see Scope::Scoped} isolated.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $scoped = [];

    /** @var array<string, string> */
    protected array $aliases = [];

    /** @var array<string, list<string>> */
    protected array $tags = [];

    /** @var array<string, array<string, (Closure(Container): mixed)|string>> */
    protected array $contextual = [];

    /** @var array<string, list<Closure(mixed, Container): mixed>> */
    protected array $extenders = [];

    /**
     * Resolution stacks for cycle detection, partitioned by execution context.
     *
     * Two coroutines resolving different graphs at the same time must not see
     * one another's frames, or the container reports phantom cycles.
     *
     * @var array<string, list<string>>
     */
    protected array $buildStacks = [];

    /**
     * Cached constructor parameter reflection, keyed by class name.
     *
     * @var array<string, list<ReflectionParameter>|null>
     */
    protected array $constructorCache = [];

    /**
     * Pre-computed construction plans from `kayra optimize`, keyed by class.
     *
     * When a class is present here, {@see build()} never touches reflection.
     *
     * @var array<class-string, list<array{k: 's', i: class-string}|array{k: 'v', v: mixed}|array{k: 'n'}>>
     */
    protected array $compiled = [];

    protected static ?Container $instance = null;

    public function __construct()
    {
        $this->singletons[self::class] = $this;
        $this->singletons[ContainerInterface::class] = $this;
    }

    /**
     * The globally available container, if one has been set.
     *
     * Used only by helper functions and legacy call sites; framework code should
     * always receive the container via injection.
     */
    public static function getInstance(): ?Container
    {
        return self::$instance;
    }

    public static function setInstance(?Container $container): void
    {
        self::$instance = $container;
    }

    /* --------------------------------------------------------------------
     | Registration
     * -------------------------------------------------------------------- */

    /**
     * Register a transient binding: a new instance on every resolution.
     *
     * @param (Closure(Container, array<string, mixed>): mixed)|class-string|null $concrete
     */
    public function bind(string $id, Closure|string|null $concrete = null, bool $lazy = false): void
    {
        $this->define(new Definition($id, $concrete, Scope::Transient, $lazy));
    }

    /**
     * Register an application-lifetime binding.
     *
     * @param (Closure(Container, array<string, mixed>): mixed)|class-string|null $concrete
     */
    public function singleton(string $id, Closure|string|null $concrete = null, bool $lazy = false): void
    {
        $this->define(new Definition($id, $concrete, Scope::Singleton, $lazy));
    }

    /**
     * Register a request-lifetime binding.
     *
     * Scoped entries are destroyed by {@see forgetScoped()} at the end of each
     * request, which is what keeps long-running workers free of state leaks.
     *
     * @param (Closure(Container, array<string, mixed>): mixed)|class-string|null $concrete
     */
    public function scoped(string $id, Closure|string|null $concrete = null, bool $lazy = false): void
    {
        $this->define(new Definition($id, $concrete, Scope::Scoped, $lazy));
    }

    public function define(Definition $definition): void
    {
        $id = $this->resolveAlias($definition->id);

        $this->definitions[$id] = $definition;

        // A re-binding invalidates anything already built for that id, in every
        // execution context.
        unset($this->singletons[$id]);
        $this->forgetScopedEverywhere($id);

        foreach ($definition->tags as $tag) {
            $this->tag($id, $tag);
        }
    }

    /**
     * Store an already-constructed object as a singleton.
     */
    public function instance(string $id, mixed $object): mixed
    {
        $id = $this->resolveAlias($id);

        unset($this->definitions[$id]);
        $this->forgetScopedEverywhere($id);
        $this->singletons[$id] = $object;

        return $object;
    }

    /**
     * Store an already-constructed object for the current request only.
     */
    public function scopedInstance(string $id, mixed $object): mixed
    {
        $id = $this->resolveAlias($id);

        $this->scoped[ExecutionContext::id()][$id] = $object;

        return $object;
    }

    public function alias(string $alias, string $id): void
    {
        if ($alias === $id) {
            throw new ContainerException("[{$alias}] cannot be aliased to itself.");
        }

        $this->aliases[$alias] = $id;
    }

    public function tag(string $id, string ...$tags): void
    {
        foreach ($tags as $tag) {
            $this->tags[$tag] ??= [];

            if (!in_array($id, $this->tags[$tag], true)) {
                $this->tags[$tag][] = $id;
            }
        }
    }

    /**
     * Resolve every entry registered under a tag.
     *
     * @return list<mixed>
     */
    public function tagged(string $tag): array
    {
        return array_map($this->get(...), $this->tags[$tag] ?? []);
    }

    /**
     * Wrap an entry after it is built — the container's decorator hook.
     *
     * @param Closure(mixed, Container): mixed $extender
     */
    public function extend(string $id, Closure $extender): void
    {
        $id = $this->resolveAlias($id);

        // Already built as a singleton? Decorate it in place.
        if (array_key_exists($id, $this->singletons)) {
            $this->singletons[$id] = $extender($this->singletons[$id], $this);

            return;
        }

        $this->extenders[$id][] = $extender;
    }

    /**
     * Begin a contextual binding: "when X needs Y, give Z".
     */
    public function when(string $concrete): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, $concrete);
    }

    /**
     * @internal Used by {@see ContextualBindingBuilder}.
     *
     * @param (Closure(Container): mixed)|string $give A container id, or a factory.
     */
    public function addContextualBinding(string $concrete, string $need, Closure|string $give): void
    {
        $this->contextual[$concrete][$need] = $give;
    }

    /* --------------------------------------------------------------------
     | Resolution
     * -------------------------------------------------------------------- */

    /**
     * Resolve an entry.
     *
     * The conditional return type lets static analysis infer the concrete class
     * whenever the id is a class name, so callers do not have to annotate every
     * resolution by hand.
     *
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     *
     * @throws NotFoundException When no entry and no autowirable class exists.
     * @throws ContainerException When the entry exists but cannot be built.
     */
    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    /**
     * Resolve an entry, optionally overriding constructor parameters by name.
     *
     * @template T of object
     * @param class-string<T>|string $id
     * @param array<string, mixed> $parameters
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function make(string $id, array $parameters = []): mixed
    {
        $id = $this->resolveAlias($id);

        // Fast paths: already-built instances. Checked before anything else.
        if (array_key_exists($id, $this->singletons)) {
            return $this->singletons[$id];
        }

        // Scoped lookups are per execution context, so a concurrent request in
        // another coroutine cannot see this one's instances.
        $context = ExecutionContext::id();

        if (isset($this->scoped[$context]) && array_key_exists($id, $this->scoped[$context])) {
            return $this->scoped[$context][$id];
        }

        if ($this->stackHas($id)) {
            throw CircularDependencyException::detected($id, $this->stackGet());
        }

        $definition = $this->definitions[$id] ?? null;

        if ($definition === null) {
            if (!class_exists($id)) {
                throw new NotFoundException(
                    "Service [{$id}] is not registered and is not an existing class."
                    . ($this->stackGet() === [] ? '' : "\n  Resolution path: " . implode(' -> ', $this->stackGet()))
                );
            }

            // Unregistered classes autowire as transient — never silently cached.
            $definition = new Definition($id);
        }

        $this->stackPush($id);

        try {
            $object = $definition->lazy && $parameters === []
                ? $this->buildLazy($definition)
                : $this->buildFrom($definition, $parameters);
        } finally {
            $this->stackPop();
        }

        foreach ($this->extenders[$id] ?? [] as $extender) {
            $object = $extender($object, $this);
        }

        // Parameter overrides produce a one-off object; never cache those.
        if ($parameters === []) {
            match ($definition->scope) {
                Scope::Singleton => $this->singletons[$id] = $object,
                Scope::Scoped    => $this->scoped[$context][$id] = $object,
                Scope::Transient => null,
            };
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    protected function buildFrom(Definition $definition, array $parameters): mixed
    {
        $concrete = $definition->concrete;

        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        return $this->build($concrete ?? $definition->id, $parameters);
    }

    /**
     * Build a lazy instance using PHP 8.4 native lazy objects.
     *
     * A ghost is used when we construct the class ourselves; a proxy is used when
     * a factory closure produces the object. Classes that cannot be made lazy
     * (internal classes, for example) fall back to eager construction.
     */
    protected function buildLazy(Definition $definition): mixed
    {
        $class = $definition->concreteClass();

        if ($class === null || !class_exists($class)) {
            return $this->buildFrom($definition, []);
        }

        try {
            $reflector = new ReflectionClass($class);

            if ($definition->concrete instanceof Closure) {
                $factory = $definition->concrete;

                // The initialiser is handed the uninitialised proxy and must
                // return the real instance. A factory that returns anything
                // else is a configuration error, so say so here rather than
                // letting PHP fail with a less specific message.
                return $reflector->newLazyProxy(function (object $proxy) use ($factory, $class): object {
                    $built = $factory($this, []);

                    return is_object($built) ? $built : throw ContainerException::forStack(
                        "The factory for [{$class}] must return an object, got " . get_debug_type($built) . '.',
                        $this->stackGet(),
                    );
                });
            }

            $constructor = $reflector->getConstructor();

            if ($constructor === null) {
                // Nothing to initialise. A ghost still has to be given an
                // initialiser, but calling __construct() on a class that has
                // none is a fatal error the moment the object is touched.
                return $reflector->newLazyGhost(static function (object $object): void {
                });
            }

            // Snapshot the stack: the initializer runs later, outside this frame.
            $stack = $this->stackGet();

            return $reflector->newLazyGhost(function (object $object) use ($class, $constructor, $stack): void {
                $previous = $this->stackGet();
                $this->stackSet($stack);

                try {
                    // invokeArgs() rather than $object->__construct(): the ghost
                    // is typed as a bare object here, and the reflection call is
                    // the same construction without pretending otherwise.
                    $constructor->invokeArgs($object, $this->resolveArguments(
                        $constructor->getParameters(),
                        [],
                        $class,
                    ));
                } finally {
                    $this->stackSet($previous);
                }
            });
        } catch (ContainerException $e) {
            throw $e;
        } catch (Throwable) {
            // Not lazifiable — build it eagerly rather than failing.
            return $this->buildFrom($definition, []);
        }
    }

    /**
     * Instantiate a concrete class, autowiring its constructor.
     *
     * @param array<string, mixed> $parameters
     */
    protected function build(string $class, array $parameters = []): object
    {
        if (!class_exists($class)) {
            throw ContainerException::forStack("Class [{$class}] does not exist.", $this->stackGet());
        }

        // Compiled path: the constructor signature was resolved at deploy time,
        // so there is no reflection in the request. Parameter overrides opt out,
        // because the plan describes the default construction only.
        if ($parameters === [] && isset($this->compiled[$class])) {
            return $this->buildCompiled($class);
        }

        $constructorParameters = $this->constructorParameters($class);

        if ($constructorParameters === null) {
            return new $class();
        }

        $arguments = $this->resolveArguments($constructorParameters, $parameters, $class);

        try {
            return new $class(...$arguments);
        } catch (Throwable $e) {
            throw ContainerException::forStack(
                "Failed to instantiate [{$class}]: {$e->getMessage()}",
                $this->stackGet(),
            );
        }
    }

    /**
     * Instantiate a class from its pre-computed plan.
     */
    protected function buildCompiled(string $class): object
    {
        $arguments = [];

        foreach ($this->compiled[$class] as $parameter) {
            // `k` discriminates the entry, so each arm sees only its own shape.
            $arguments[] = match ($parameter['k']) {
                ContainerCompiler::KIND_SERVICE => $this->make($parameter['i']),
                ContainerCompiler::KIND_VALUE   => $parameter['v'],
                default                         => null,
            };
        }

        try {
            return new $class(...$arguments);
        } catch (Throwable $e) {
            throw ContainerException::forStack(
                "Failed to instantiate [{$class}] from the compiled plan: {$e->getMessage()}. "
                . 'Run `kayra optimize:clear` if the plan is stale.',
                $this->stackGet(),
            );
        }
    }

    /**
     * Install a construction plan produced by {@see ContainerCompiler}.
     *
     * @param array<class-string, list<array<string, mixed>>> $plan
     */
    public function useCompiled(array $plan): void
    {
        $this->compiled = $plan;
    }

    public function isCompiled(): bool
    {
        return $this->compiled !== [];
    }

    public function compiledCount(): int
    {
        return count($this->compiled);
    }

    /**
     * Whether any contextual binding targets this class.
     *
     * @internal Used by {@see ContainerCompiler} to decide what stays dynamic.
     */
    public function hasContextualBindings(string $concrete): bool
    {
        return isset($this->contextual[$concrete]);
    }

    /**
     * Constructor parameters for a class, cached. Null means "no constructor".
     *
     * @return list<ReflectionParameter>|null
     */
    protected function constructorParameters(string $class): ?array
    {
        if (array_key_exists($class, $this->constructorCache)) {
            return $this->constructorCache[$class];
        }

        if (!class_exists($class) && !interface_exists($class)) {
            throw ContainerException::forStack(
                "Cannot build [{$class}]: no such class or interface.",
                $this->stackGet(),
            );
        }

        $reflector = new ReflectionClass($class);

        if (!$reflector->isInstantiable()) {
            $reason = $reflector->isInterface() ? 'is an interface' : ($reflector->isAbstract() ? 'is abstract' : 'is not instantiable');

            throw ContainerException::forStack(
                "Cannot build [{$class}]: it {$reason}. Bind it to a concrete implementation.",
                $this->stackGet(),
            );
        }

        $constructor = $reflector->getConstructor();

        return $this->constructorCache[$class] = $constructor?->getParameters();
    }

    /**
     * Resolve a parameter list into positional arguments.
     *
     * @param list<ReflectionParameter> $parameters
     * @param array<string, mixed>      $overrides
     * @return list<mixed>
     */
    protected function resolveArguments(array $parameters, array $overrides, ?string $context = null): array
    {
        $arguments = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $overrides)) {
                if ($parameter->isVariadic() && is_array($overrides[$name])) {
                    array_push($arguments, ...$overrides[$name]);

                    continue;
                }

                $arguments[] = $overrides[$name];

                continue;
            }

            if ($parameter->isVariadic()) {
                continue;
            }

            $arguments[] = $this->resolveParameter($parameter, $context);
        }

        return $arguments;
    }

    protected function resolveParameter(ReflectionParameter $parameter, ?string $context): mixed
    {
        $name = $parameter->getName();
        $type = $parameter->getType();

        // 1. Contextual binding by parameter name: ->needs('$timeout')
        if ($context !== null && isset($this->contextual[$context]['$' . $name])) {
            return $this->resolveContextual($this->contextual[$context]['$' . $name]);
        }

        if ($type === null) {
            return $this->parameterFallback($parameter, 'it has no type declaration');
        }

        if ($type instanceof ReflectionIntersectionType) {
            return $this->parameterFallback(
                $parameter,
                'intersection types cannot be autowired; register an explicit binding',
            );
        }

        /** @var list<ReflectionNamedType> $candidates */
        $candidates = $type instanceof ReflectionUnionType
            ? array_values(array_filter($type->getTypes(), static fn ($t): bool => $t instanceof ReflectionNamedType))
            : [$type];

        foreach ($candidates as $candidate) {
            if ($candidate->isBuiltin()) {
                continue;
            }

            $class = $candidate->getName();

            // 2. Contextual binding by type
            if ($context !== null && isset($this->contextual[$context][$class])) {
                return $this->resolveContextual($this->contextual[$context][$class]);
            }

            try {
                return $this->make($class);
            } catch (CircularDependencyException $e) {
                throw $e;
            } catch (NotFoundException | ContainerException $e) {
                // A union may have another viable branch; remember the failure.
                $lastError = $e;
            }
        }

        return $this->parameterFallback(
            $parameter,
            isset($lastError) ? $lastError->getMessage() : 'no binding matched its type',
        );
    }

    /**
     * @param (Closure(Container): mixed)|string $give A container id, or a factory.
     */
    protected function resolveContextual(Closure|string $give): mixed
    {
        return $give instanceof Closure ? $give($this) : $this->make($give);
    }

    protected function parameterFallback(ReflectionParameter $parameter, string $reason): mixed
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        $declaring = $parameter->getDeclaringClass()?->getName() ?? 'closure';

        throw ContainerException::forStack(
            "Cannot resolve parameter \${$parameter->getName()} of [{$declaring}]: {$reason}.",
            $this->stackGet(),
        );
    }

    public function has(string $id): bool
    {
        $id = $this->resolveAlias($id);

        if ($this->bound($id)) {
            return true;
        }

        return class_exists($id) && (new ReflectionClass($id))->isInstantiable();
    }

    /**
     * Whether a concrete instance already exists, without creating one.
     *
     * `bound()` answers "is this id known?", which is true for anything with a
     * definition. This answers "has it actually been built?", which is what a
     * teardown routine needs: resolving a service purely to clean it up would
     * construct the very thing the request never used.
     */
    public function hasInstance(string $id): bool
    {
        $id = $this->resolveAlias($id);
        $context = ExecutionContext::id();

        return array_key_exists($id, $this->singletons)
            || (isset($this->scoped[$context]) && array_key_exists($id, $this->scoped[$context]));
    }

    public function bound(string $id): bool
    {
        $id = $this->resolveAlias($id);
        $context = ExecutionContext::id();

        return isset($this->definitions[$id])
            || array_key_exists($id, $this->singletons)
            || (isset($this->scoped[$context]) && array_key_exists($id, $this->scoped[$context]));
    }

    /* --------------------------------------------------------------------
     | Invocation
     * -------------------------------------------------------------------- */

    /**
     * Call a callable, autowiring its parameters.
     *
     * Accepts closures, "Class@method" strings, [$object, 'method'] and
     * [Class::class, 'method'] pairs.
     *
     * @param (callable(): mixed)|array{0: object|class-string, 1: string}|string $callback
     * @param array<string, mixed> $parameters
     */
    public function call(callable|array|string $callback, array $parameters = []): mixed
    {
        [$callable, $reflector, $context] = $this->reflectCallable($callback);

        $arguments = $this->resolveArguments($reflector->getParameters(), $parameters, $context);

        return $callable(...$arguments);
    }

    /**
     * @param (callable(): mixed)|array{0: object|class-string, 1: string}|string $callback
     *
     * @return array{0: callable(): mixed, 1: ReflectionFunctionAbstract, 2: ?string}
     */
    protected function reflectCallable(callable|array|string $callback): array
    {
        if (is_string($callback) && str_contains($callback, '@')) {
            [$class, $method] = explode('@', $callback, 2);
            $callback = [$class, $method];
        }

        if (is_array($callback)) {
            [$target, $method] = $callback;

            if (!is_string($method)) {
                throw new ContainerException(
                    'A [$target, $method] callable needs a method name, got ' . get_debug_type($method) . '.',
                );
            }

            $object = is_string($target) ? $this->make($target) : $target;
            $class = is_string($target) ? $target : $target::class;

            if (!is_object($object) || !method_exists($object, $method)) {
                throw new ContainerException("Method [{$class}::{$method}()] does not exist.");
            }

            return [$object->{$method}(...), new ReflectionMethod($object, $method), $class];
        }

        if (is_string($callback) && class_exists($callback)) {
            $object = $this->make($callback);

            if (!method_exists($object, '__invoke')) {
                throw new ContainerException("Class [{$callback}] is not invokable.");
            }

            return [$object, new ReflectionMethod($object, '__invoke'), $callback];
        }

        // Everything that is not a string has been handled above, so by here a
        // non-callable can only be a string naming nothing callable.
        if (!is_callable($callback)) {
            throw new ContainerException(
                "Cannot call [{$callback}]: it is neither a function, a \"Class@method\" string, "
                . 'nor an invokable class.',
            );
        }

        return [$callback, new ReflectionFunction($callback(...)), null];
    }

    /* --------------------------------------------------------------------
     | Lifetime management
     * -------------------------------------------------------------------- */

    /**
     * Drop the request-scoped instances belonging to the current request.
     *
     * Long-running runtimes call this after every request. Only the calling
     * context's entries are dropped: under a coroutine runtime other requests
     * are still in flight, and clearing their state here is precisely the bug
     * this partitioning exists to prevent.
     */
    public function forgetScoped(): void
    {
        $context = ExecutionContext::id();

        unset($this->scoped[$context], $this->buildStacks[$context]);
    }

    /**
     * Drop request-scoped instances for every context.
     *
     * Only safe when no request is in flight — at shutdown, in tests, or when a
     * binding is being redefined.
     */
    public function forgetAllScoped(): void
    {
        $this->scoped = [];
        $this->buildStacks = [];
    }

    /**
     * Remove an entry entirely: its definition, its instances and its extenders.
     *
     * Afterwards the id is unknown, so resolving it again autowires the class
     * from scratch rather than using whatever factory was registered. When the
     * intent is "rebuild this from its definition", use {@see forgetInstance()}.
     */
    public function forget(string $id): void
    {
        $id = $this->resolveAlias($id);

        unset($this->singletons[$id], $this->definitions[$id], $this->extenders[$id]);
        $this->forgetScopedEverywhere($id);
    }

    /**
     * Drop the cached instance but keep the definition.
     *
     * The next resolution runs the registered factory again — which is what you
     * want after changing configuration the factory reads.
     */
    public function forgetInstance(string $id): void
    {
        $id = $this->resolveAlias($id);

        unset($this->singletons[$id]);
        $this->forgetScopedEverywhere($id);
    }

    /**
     * Remove one id from every context's scoped bucket.
     */
    private function forgetScopedEverywhere(string $id): void
    {
        foreach ($this->scoped as $context => $entries) {
            unset($this->scoped[$context][$id]);

            if ($this->scoped[$context] === []) {
                unset($this->scoped[$context]);
            }
        }
    }

    /* --------------------------------------------------------------------
     | Resolution stack, partitioned by execution context
     * -------------------------------------------------------------------- */

    /**
     * @return list<string>
     */
    protected function stackGet(): array
    {
        return $this->buildStacks[ExecutionContext::id()] ?? [];
    }

    protected function stackHas(string $id): bool
    {
        $stack = $this->buildStacks[ExecutionContext::id()] ?? null;

        return $stack !== null && in_array($id, $stack, true);
    }

    protected function stackPush(string $id): void
    {
        $this->buildStacks[ExecutionContext::id()][] = $id;
    }

    protected function stackPop(): void
    {
        $context = ExecutionContext::id();

        if (!isset($this->buildStacks[$context])) {
            return;
        }

        array_pop($this->buildStacks[$context]);

        // Do not let finished contexts accumulate in a long-running worker.
        if ($this->buildStacks[$context] === []) {
            unset($this->buildStacks[$context]);
        }
    }

    /**
     * @param list<string> $stack
     */
    protected function stackSet(array $stack): void
    {
        $context = ExecutionContext::id();

        if ($stack === []) {
            unset($this->buildStacks[$context]);

            return;
        }

        $this->buildStacks[$context] = $stack;
    }

    /**
     * How many execution contexts currently hold scoped state.
     *
     * Surfaced for diagnostics: a number that grows without bound means some
     * runtime is not calling {@see forgetScoped()}.
     */
    public function scopedContextCount(): int
    {
        return count($this->scoped);
    }

    /**
     * Follow an alias chain to the canonical identifier.
     */
    public function resolveAlias(string $id): string
    {
        $seen = [];

        while (isset($this->aliases[$id])) {
            if (isset($seen[$id])) {
                throw new ContainerException("Alias loop detected for [{$id}].");
            }

            $seen[$id] = true;
            $id = $this->aliases[$id];
        }

        return $id;
    }

    /**
     * @return array<string, Definition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * @return array<string, string>
     */
    public function aliases(): array
    {
        return $this->aliases;
    }
}
