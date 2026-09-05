<?php

declare(strict_types=1);

namespace Kayra\Auth\Access;

use Closure;
use Kayra\Auth\Authenticatable;
use Kayra\Container\Container;
use InvalidArgumentException;

/**
 * Decides what a user is allowed to do.
 *
 * Two ways to express a rule:
 *
 *   Gates    — a closure for a standalone ability: `$gate->define('view-admin', ...)`
 *   Policies — a class per model, one method per ability: `PostPolicy::update()`
 *
 * A policy is looked up first, because most rules are about a specific record
 * ("may this user edit *this* post"), and a class keeps those together.
 *
 * The default is denial. An ability with no rule returns false rather than
 * true, so forgetting to write a policy locks a feature down instead of
 * opening it up.
 */
final class Gate
{
    /** @var array<string, Closure(Authenticatable|null, mixed...): (bool|Response)> */
    private array $abilities = [];

    /** @var array<class-string, class-string> Model => policy. */
    private array $policies = [];

    /** @var list<Closure(Authenticatable|null, string): (bool|null)> */
    private array $before = [];

    public function __construct(
        private readonly Container $container,
        private readonly ?Authenticatable $user = null,
    ) {
    }

    /**
     * A copy of this gate bound to a different user.
     *
     * Rules are shared; only the subject changes. Used by `Gate::forUser()` in
     * admin tooling that answers "what could *they* do?".
     */
    public function forUser(?Authenticatable $user): self
    {
        $clone = new self($this->container, $user);
        $clone->abilities = $this->abilities;
        $clone->policies = $this->policies;
        $clone->before = $this->before;

        return $clone;
    }

    /**
     * Define a standalone ability.
     *
     * @param Closure(Authenticatable|null, mixed...): (bool|Response) $callback
     */
    public function define(string $ability, Closure $callback): self
    {
        $this->abilities[$ability] = $callback;

        return $this;
    }

    /**
     * Map a model class to the policy that governs it.
     *
     * @param class-string $model
     * @param class-string $policy
     */
    public function policy(string $model, string $policy): self
    {
        $this->policies[$model] = $policy;

        return $this;
    }

    /**
     * Run before every check. Returning true or false short-circuits.
     *
     * This is where a super-admin bypass belongs — as one rule, rather than an
     * `|| $user->isAdmin()` repeated in every policy method where it is easy to
     * forget one.
     *
     * @param Closure(Authenticatable|null, string): (bool|null) $callback
     */
    public function before(Closure $callback): self
    {
        $this->before[] = $callback;

        return $this;
    }

    /* --------------------------------------------------------------------
     | Checking
     * -------------------------------------------------------------------- */

    public function allows(string $ability, mixed ...$arguments): bool
    {
        return $this->inspect($ability, ...$arguments)->allowed();
    }

    public function denies(string $ability, mixed ...$arguments): bool
    {
        return !$this->allows($ability, ...$arguments);
    }

    /**
     * Check an ability and throw when it is denied.
     *
     * @throws AuthorizationException
     */
    public function authorize(string $ability, mixed ...$arguments): Response
    {
        $response = $this->inspect($ability, ...$arguments);

        if (!$response->allowed()) {
            throw new AuthorizationException($response->message(), $response->status());
        }

        return $response;
    }

    /**
     * Check an ability and get the reasoning, not just a boolean.
     */
    public function inspect(string $ability, mixed ...$arguments): Response
    {
        foreach ($this->before as $callback) {
            $result = $callback($this->user, $ability);

            if ($result !== null) {
                return $result === true
                    ? Response::allow()
                    : Response::deny();
            }
        }

        // array_values(): a variadic can carry string keys when the caller used
        // named arguments, and resolve() both indexes [0] and spreads these
        // into a policy method positionally.
        $result = $this->resolve($ability, array_values($arguments));

        return match (true) {
            $result instanceof Response => $result,
            $result === true            => Response::allow(),
            // Denial is the default, including for an ability nobody defined.
            default                     => Response::deny(),
        };
    }

    /**
     * @param list<mixed> $arguments
     */
    private function resolve(string $ability, array $arguments): bool|Response|null
    {
        // A policy governs the first argument when that argument is a model.
        $subject = $arguments[0] ?? null;
        $policy = $this->policyFor($subject);

        if ($policy !== null && method_exists($policy, $ability)) {
            /** @var bool|Response|null $result */
            $result = $policy->{$ability}($this->user, ...$arguments);

            return $result;
        }

        if (isset($this->abilities[$ability])) {
            return ($this->abilities[$ability])($this->user, ...$arguments);
        }

        return null;
    }

    /**
     * The policy instance for a subject, if one is registered.
     *
     * The subject may be an instance or a class name, so that abilities with no
     * particular record — "create a post" — can still reach the policy.
     */
    private function policyFor(mixed $subject): ?object
    {
        $class = match (true) {
            is_object($subject) => $subject::class,
            is_string($subject) && class_exists($subject) => $subject,
            default => null,
        };

        if ($class === null) {
            return null;
        }

        // Walk up the hierarchy so a policy registered for a parent applies.
        for ($current = $class; $current !== false; $current = get_parent_class($current)) {
            if (isset($this->policies[$current])) {
                return $this->container->get($this->policies[$current]);
            }
        }

        return null;
    }

    /**
     * @return array<class-string, class-string>
     */
    public function policies(): array
    {
        return $this->policies;
    }

    /**
     * @return list<string>
     */
    public function abilities(): array
    {
        return array_keys($this->abilities);
    }
}
