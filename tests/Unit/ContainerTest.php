<?php

declare(strict_types=1);

namespace Kayra\Tests\Unit;

use Kayra\Container\CircularDependencyException;
use Kayra\Container\Container;
use Kayra\Container\ContainerException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;

interface Mailer
{
}

final class SmtpMailer implements Mailer
{
    public static int $instances = 0;

    public function __construct()
    {
        self::$instances++;
    }
}

final class NeedsMailer
{
    public function __construct(public Mailer $mailer)
    {
    }
}

final class Expensive
{
    public static int $constructed = 0;

    public string $value;

    public function __construct()
    {
        self::$constructed++;
        self::$constructed > 0 && $this->value = 'built';
    }
}

final class CycleA
{
    public function __construct(public CycleB $b)
    {
    }
}

final class CycleB
{
    public function __construct(public CycleA $a)
    {
    }
}

final class WithScalar
{
    public function __construct(public Mailer $mailer, public int $retries = 1)
    {
    }
}

#[CoversClass(Container::class)]
final class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->bind(Mailer::class, SmtpMailer::class);
        SmtpMailer::$instances = 0;
        Expensive::$constructed = 0;
    }

    #[Test]
    public function it_resolves_an_interface_to_its_bound_concrete(): void
    {
        $this->assertInstanceOf(SmtpMailer::class, $this->container->get(Mailer::class));
    }

    #[Test]
    public function transient_bindings_return_a_new_instance_each_time(): void
    {
        $this->container->get(Mailer::class);
        $this->container->get(Mailer::class);

        $this->assertSame(2, SmtpMailer::$instances);
    }

    #[Test]
    public function singletons_are_built_once(): void
    {
        $this->container->singleton('svc', static fn (): SmtpMailer => new SmtpMailer());

        $this->assertSame($this->container->get('svc'), $this->container->get('svc'));
        $this->assertSame(1, SmtpMailer::$instances);
    }

    #[Test]
    public function scoped_bindings_survive_within_a_request_and_are_dropped_after(): void
    {
        $this->container->scoped('svc', static fn (): SmtpMailer => new SmtpMailer());

        $first = $this->container->get('svc');
        $this->assertSame($first, $this->container->get('svc'));

        $this->container->forgetScoped();

        $this->assertNotSame($first, $this->container->get('svc'));
    }

    #[Test]
    public function singletons_survive_forget_scoped(): void
    {
        $this->container->singleton('svc', static fn (): SmtpMailer => new SmtpMailer());
        $first = $this->container->get('svc');

        $this->container->forgetScoped();

        $this->assertSame($first, $this->container->get('svc'));
    }

    #[Test]
    public function it_autowires_nested_dependencies(): void
    {
        $this->assertInstanceOf(SmtpMailer::class, $this->container->get(NeedsMailer::class)->mailer);
    }

    #[Test]
    public function lazy_bindings_defer_construction_until_first_use(): void
    {
        $this->container->singleton(Expensive::class, null, lazy: true);

        $instance = $this->container->get(Expensive::class);

        $this->assertSame(0, Expensive::$constructed, 'Constructor ran too early.');

        $this->assertSame('built', $instance->value);
        $this->assertSame(1, Expensive::$constructed);
    }

    #[Test]
    public function it_detects_circular_dependencies(): void
    {
        $this->expectException(CircularDependencyException::class);

        $this->container->get(CycleA::class);
    }

    #[Test]
    public function it_throws_a_psr_not_found_exception_for_unknown_ids(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);

        $this->container->get('No\Such\Service');
    }

    #[Test]
    public function contextual_bindings_apply_by_parameter_name(): void
    {
        $this->container->when(WithScalar::class)->needs('$retries')->give(static fn (): int => 7);

        $this->assertSame(7, $this->container->get(WithScalar::class)->retries);
    }

    #[Test]
    public function contextual_bindings_apply_by_type(): void
    {
        $sentinel = new SmtpMailer();
        $this->container->when(NeedsMailer::class)->needs(Mailer::class)->give(static fn (): Mailer => $sentinel);

        $this->assertSame($sentinel, $this->container->get(NeedsMailer::class)->mailer);
    }

    #[Test]
    public function tagged_services_resolve_together(): void
    {
        $this->container->bind('a', static fn (): string => 'A');
        $this->container->bind('b', static fn (): string => 'B');
        $this->container->tag('a', 'letters');
        $this->container->tag('b', 'letters');

        $this->assertSame(['A', 'B'], $this->container->tagged('letters'));
    }

    #[Test]
    public function extend_decorates_a_resolved_service(): void
    {
        $this->container->singleton('svc', static fn (): string => 'base');
        $this->container->extend('svc', static fn (string $v): string => $v . '+ext');

        $this->assertSame('base+ext', $this->container->get('svc'));
    }

    #[Test]
    public function call_injects_arguments_by_type_and_name(): void
    {
        $result = $this->container->call(
            static fn (Mailer $mailer, string $id): string => $mailer::class . ':' . $id,
            ['id' => '42'],
        );

        $this->assertSame(SmtpMailer::class . ':42', $result);
    }

    #[Test]
    public function it_reports_a_useful_error_for_unresolvable_scalars(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/Cannot resolve parameter \$id/');

        $this->container->call(static fn (string $id): string => $id);
    }

    #[Test]
    public function aliases_resolve_to_the_canonical_binding(): void
    {
        $this->container->singleton('canonical', static fn (): string => 'value');
        $this->container->alias('nickname', 'canonical');

        $this->assertSame('value', $this->container->get('nickname'));
    }

    #[Test]
    public function parameter_overrides_are_never_cached(): void
    {
        $this->container->singleton(WithScalar::class);

        $custom = $this->container->make(WithScalar::class, ['retries' => 9]);
        $normal = $this->container->make(WithScalar::class);

        $this->assertSame(9, $custom->retries);
        $this->assertSame(1, $normal->retries);
        $this->assertNotSame($custom, $normal);
    }
}
