<?php

declare(strict_types=1);

namespace Kayra\Tests\Unit;

use Kayra\Container\Container;
use Kayra\Container\ContainerCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

final class CompilerLeaf
{
}

final class CompilerMid
{
    public function __construct(public CompilerLeaf $leaf)
    {
    }
}

final class CompilerRoot
{
    public function __construct(public CompilerMid $mid, public CompilerLeaf $leaf, public int $n = 5)
    {
    }
}

final class CompilerNullable
{
    public function __construct(public ?CompilerLeaf $leaf = null, public ?string $label = null)
    {
    }
}

interface CompilerContract
{
}

final class CompilerImplementation implements CompilerContract
{
}

final class CompilerNeedsInterface
{
    public function __construct(public CompilerContract $contract)
    {
    }
}

final class CompilerVariadic
{
    /** @var list<CompilerLeaf> */
    public array $leaves;

    public function __construct(CompilerLeaf ...$leaves)
    {
        $this->leaves = $leaves;
    }
}

/**
 * Records which construction path the container took for each class.
 */
final class InstrumentedContainer extends Container
{
    /** @var list<string> */
    public array $reflected = [];

    /** @var list<string> */
    public array $planned = [];

    protected function constructorParameters(string $class): ?array
    {
        $this->reflected[] = $class;

        return parent::constructorParameters($class);
    }

    protected function buildCompiled(string $class): object
    {
        $this->planned[] = $class;

        return parent::buildCompiled($class);
    }
}

#[CoversClass(ContainerCompiler::class)]
final class ContainerCompilerTest extends TestCase
{
    /**
     * @return array<class-string, list<array<string, mixed>>>
     */
    private function planFor(string ...$roots): array
    {
        $source = new Container();

        foreach ($roots as $root) {
            $source->bind($root);
        }

        return (new ContainerCompiler($source))->compile($roots);
    }

    #[Test]
    public function it_walks_the_whole_dependency_graph(): void
    {
        $plan = $this->planFor(CompilerRoot::class);

        $this->assertSame(
            [CompilerLeaf::class, CompilerMid::class, CompilerRoot::class],
            array_keys($plan),
        );
    }

    #[Test]
    public function the_plan_is_var_export_safe(): void
    {
        $plan = $this->planFor(CompilerRoot::class);

        $this->assertSame($plan, eval('return ' . var_export($plan, true) . ';'));
    }

    #[Test]
    public function a_compiled_container_uses_no_reflection(): void
    {
        $plan = $this->planFor(CompilerRoot::class);

        $container = new InstrumentedContainer();
        $container->bind(CompilerRoot::class);
        $container->bind(CompilerMid::class);
        $container->bind(CompilerLeaf::class);
        $container->useCompiled($plan);

        $root = $container->get(CompilerRoot::class);

        $this->assertInstanceOf(CompilerLeaf::class, $root->mid->leaf);
        $this->assertSame(5, $root->n, 'Default scalar should be baked into the plan.');
        $this->assertSame([], $container->reflected, 'Reflection was used despite a compiled plan.');
        // Leaf is transient and appears twice in the graph.
        $this->assertCount(4, $container->planned);
    }

    #[Test]
    public function a_compiled_container_produces_the_same_graph_as_reflection(): void
    {
        $plain = new Container();
        $plain->bind(CompilerRoot::class);
        $reflected = $plain->get(CompilerRoot::class);

        $compiled = new Container();
        $compiled->bind(CompilerRoot::class);
        $compiled->useCompiled($this->planFor(CompilerRoot::class));
        $planned = $compiled->get(CompilerRoot::class);

        $this->assertEquals($reflected, $planned);
    }

    #[Test]
    public function parameter_overrides_bypass_the_plan_for_that_class_only(): void
    {
        $container = new InstrumentedContainer();
        $container->bind(CompilerRoot::class);
        $container->useCompiled($this->planFor(CompilerRoot::class));

        $custom = $container->make(CompilerRoot::class, ['n' => 99]);

        $this->assertSame(99, $custom->n);
        $this->assertSame([CompilerRoot::class], $container->reflected);
        $this->assertNotSame([], $container->planned, 'Dependencies should still use the plan.');
    }

    #[Test]
    public function classes_with_contextual_bindings_are_left_dynamic(): void
    {
        $source = new Container();
        $source->bind(CompilerRoot::class);
        $source->when(CompilerRoot::class)->needs('$n')->give(static fn (): int => 42);

        $compiler = new ContainerCompiler($source);
        $plan = $compiler->compile([CompilerRoot::class]);

        $this->assertArrayNotHasKey(CompilerRoot::class, $plan);
        $this->assertStringContainsString('contextual', implode(' ', $compiler->skipped()));

        // The contextual value must still be honoured at run time.
        $source->useCompiled($plan);
        $this->assertSame(42, $source->get(CompilerRoot::class)->n);
    }

    #[Test]
    public function variadic_constructors_are_left_dynamic(): void
    {
        $plan = $this->planFor(CompilerVariadic::class);

        $this->assertArrayNotHasKey(CompilerVariadic::class, $plan);
    }

    #[Test]
    public function nullable_and_defaulted_parameters_compile(): void
    {
        $plan = $this->planFor(CompilerNullable::class);

        $this->assertArrayHasKey(CompilerNullable::class, $plan);

        $container = new Container();
        $container->bind(CompilerNullable::class);
        $container->useCompiled($plan);

        $instance = $container->get(CompilerNullable::class);

        // A nullable class-typed parameter still resolves when the class exists.
        $this->assertInstanceOf(CompilerLeaf::class, $instance->leaf);
        $this->assertNull($instance->label);
    }

    #[Test]
    public function an_interface_dependency_is_recorded_but_left_to_its_binding(): void
    {
        // The realistic shape: a concrete class that depends on an interface.
        // The interface itself cannot be built, so it is reported as skipped and
        // resolution falls through to whatever binding provides it.
        $compiler = new ContainerCompiler(new Container());
        $plan = $compiler->compile([CompilerNeedsInterface::class]);

        $this->assertArrayHasKey(CompilerNeedsInterface::class, $plan);
        $this->assertSame(
            [['k' => ContainerCompiler::KIND_SERVICE, 'i' => CompilerContract::class]],
            $plan[CompilerNeedsInterface::class],
        );

        $this->assertArrayNotHasKey(CompilerContract::class, $plan);
        $this->assertStringContainsString('not instantiable', implode(' ', $compiler->skipped()));
    }

    #[Test]
    public function a_compiled_plan_still_honours_the_interface_binding(): void
    {
        $container = new Container();
        $container->bind(CompilerContract::class, CompilerImplementation::class);
        $container->useCompiled(
            (new ContainerCompiler($container))->compile([CompilerNeedsInterface::class]),
        );

        $this->assertInstanceOf(
            CompilerImplementation::class,
            $container->get(CompilerNeedsInterface::class)->contract,
        );
    }

    #[Test]
    public function a_stale_plan_fails_loudly_instead_of_producing_a_broken_object(): void
    {
        $container = new Container();
        $container->bind(CompilerRoot::class);
        $container->useCompiled([
            CompilerRoot::class => [['k' => ContainerCompiler::KIND_SERVICE, 'i' => 'Gone\Missing\Service']],
        ]);

        $this->expectException(Throwable::class);

        $container->get(CompilerRoot::class);
    }

    #[Test]
    public function compilation_is_deterministic(): void
    {
        $this->assertSame(
            $this->planFor(CompilerRoot::class),
            $this->planFor(CompilerRoot::class),
            'The same input must produce a byte-identical plan, or deploys churn.',
        );
    }
}
