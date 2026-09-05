<?php

declare(strict_types=1);

namespace Kayra\Tests\Unit;

use Kayra\Auth\Access\AuthorizationException;
use Kayra\Auth\Access\Gate;
use Kayra\Auth\Access\Policy;
use Kayra\Auth\Access\Response;
use Kayra\Auth\Authenticatable;
use Kayra\Container\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/* ----------------------------------------------------------------- fixtures */

final class GateUser implements Authenticatable
{
    public function __construct(
        public readonly int $id,
        public readonly bool $admin = false,
    ) {
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordHashSnapshot(): string
    {
        return '';
    }
}

class GateDocument
{
    public function __construct(public readonly int $ownerId)
    {
    }
}

final class GateSecretDocument extends GateDocument
{
}

final class GateDocumentPolicy extends Policy
{
    public function viewAny(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    public function create(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    public function update(?Authenticatable $user, GateDocument $document): bool
    {
        return $user instanceof GateUser && $document->ownerId === $user->id;
    }

    public function delete(?Authenticatable $user, GateDocument $document): Response
    {
        if (!$user instanceof GateUser) {
            return Response::deny('You must be signed in.');
        }

        if ($document->ownerId !== $user->id) {
            // Existence is privileged here: a 403 would confirm the document
            // exists and belongs to someone else.
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}

/* -------------------------------------------------------------------- tests */

#[CoversClass(Gate::class)]
#[CoversClass(Response::class)]
final class AuthorizationTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    private function gate(?Authenticatable $user): Gate
    {
        return (new Gate($this->container, $user))
            ->policy(GateDocument::class, GateDocumentPolicy::class);
    }

    /* ---------------------------------------------------------- fail closed */

    #[Test]
    public function an_undefined_ability_is_denied(): void
    {
        // The default has to be denial: forgetting to write a rule must lock a
        // feature down, not open it up.
        $this->assertFalse($this->gate(new GateUser(1))->allows('nobody-defined-this'));
    }

    #[Test]
    public function a_policy_method_that_does_not_exist_is_denied(): void
    {
        $this->assertFalse(
            $this->gate(new GateUser(1))->allows('archive', new GateDocument(1)),
        );
    }

    /* -------------------------------------------------------------- policies */

    #[Test]
    public function a_policy_governs_its_model(): void
    {
        $owner = new GateUser(1);
        $other = new GateUser(2);
        $document = new GateDocument(ownerId: 1);

        $this->assertTrue($this->gate($owner)->allows('update', $document));
        $this->assertFalse($this->gate($other)->allows('update', $document));
    }

    #[Test]
    public function a_policy_applies_to_subclasses(): void
    {
        // Registered for GateDocument; GateSecretDocument extends it.
        $this->assertTrue(
            $this->gate(new GateUser(1))->allows('update', new GateSecretDocument(1)),
        );
    }

    #[Test]
    public function a_class_name_reaches_the_policy_for_record_less_abilities(): void
    {
        // "May I create one?" has no particular record to pass.
        $this->assertTrue($this->gate(new GateUser(1))->allows('create', GateDocument::class));
        $this->assertFalse($this->gate(null)->allows('create', GateDocument::class));
    }

    #[Test]
    public function a_policy_can_answer_with_a_reason(): void
    {
        $response = $this->gate(null)->inspect('delete', new GateDocument(1));

        $this->assertTrue($response->denied());
        $this->assertSame('You must be signed in.', $response->message());
    }

    #[Test]
    public function a_policy_can_hide_a_record_rather_than_forbid_it(): void
    {
        $response = $this->gate(new GateUser(2))->inspect('delete', new GateDocument(1));

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status(), 'Existence itself can be privileged.');
    }

    /* ----------------------------------------------------------------- gates */

    #[Test]
    public function a_closure_ability_works_without_a_model(): void
    {
        $gate = $this->gate(new GateUser(1, admin: true))
            ->define('view-dashboard', static fn (?Authenticatable $u): bool => $u instanceof GateUser && $u->admin);

        $this->assertTrue($gate->allows('view-dashboard'));
        $this->assertFalse($this->gate(new GateUser(2))->define(
            'view-dashboard',
            static fn (?Authenticatable $u): bool => $u instanceof GateUser && $u->admin,
        )->allows('view-dashboard'));
    }

    #[Test]
    public function a_policy_takes_precedence_over_a_gate_of_the_same_name(): void
    {
        // Most rules concern a specific record, so the record-aware rule wins.
        $gate = $this->gate(new GateUser(2))
            ->define('update', static fn (): bool => true);

        $this->assertFalse($gate->allows('update', new GateDocument(1)));
    }

    /* ---------------------------------------------------------------- before */

    #[Test]
    public function a_before_callback_can_grant_everything(): void
    {
        // The super-admin bypass belongs here, as one rule — not as an
        // `|| isAdmin()` repeated in every policy method, where one gets missed.
        $gate = $this->gate(new GateUser(9, admin: true))
            ->before(static fn (?Authenticatable $u): ?bool => ($u instanceof GateUser && $u->admin) ? true : null);

        $this->assertTrue($gate->allows('update', new GateDocument(ownerId: 1)));
        $this->assertTrue($gate->allows('anything-at-all'));
    }

    #[Test]
    public function a_before_callback_returning_null_defers_to_the_policy(): void
    {
        $gate = $this->gate(new GateUser(2))
            ->before(static fn (): ?bool => null);

        $this->assertFalse($gate->allows('update', new GateDocument(ownerId: 1)));
    }

    #[Test]
    public function a_before_callback_can_deny_outright(): void
    {
        $gate = $this->gate(new GateUser(1))
            ->before(static fn (): bool => false);

        $this->assertFalse($gate->allows('update', new GateDocument(ownerId: 1)));
    }

    /* ------------------------------------------------------------- authorize */

    #[Test]
    public function authorize_returns_quietly_when_allowed(): void
    {
        $response = $this->gate(new GateUser(1))->authorize('update', new GateDocument(1));

        $this->assertTrue($response->allowed());
    }

    #[Test]
    public function authorize_throws_a_403_when_denied(): void
    {
        try {
            $this->gate(new GateUser(2))->authorize('update', new GateDocument(1));
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    #[Test]
    public function authorize_carries_the_status_the_policy_chose(): void
    {
        try {
            $this->gate(new GateUser(2))->authorize('delete', new GateDocument(1));
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    /* --------------------------------------------------------------- forUser */

    #[Test]
    public function for_user_answers_on_behalf_of_someone_else(): void
    {
        $gate = $this->gate(new GateUser(1));
        $document = new GateDocument(ownerId: 1);

        $this->assertTrue($gate->allows('update', $document));
        $this->assertFalse($gate->forUser(new GateUser(2))->allows('update', $document));
        // The original gate is unchanged.
        $this->assertTrue($gate->allows('update', $document));
    }

    #[Test]
    public function a_guest_is_denied_by_default(): void
    {
        $this->assertFalse($this->gate(null)->allows('viewAny', GateDocument::class));
        $this->assertTrue($this->gate(new GateUser(1))->allows('viewAny', GateDocument::class));
    }
}
