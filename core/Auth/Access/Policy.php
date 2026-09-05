<?php

declare(strict_types=1);

namespace Kayra\Auth\Access;

use Kayra\Auth\Authenticatable;

/**
 * Optional base class for a policy.
 *
 * Extending it is not required — {@see Gate} calls any object with a method
 * matching the ability. It exists to make the conventional method names
 * discoverable, and to document the signature each one receives.
 *
 * Every method takes the user first and may return a bool or a {@see Response}.
 * A method you do not define simply denies, so an incomplete policy fails
 * closed.
 */
abstract class Policy
{
    /**
     * May the user list records of this type?
     */
    public function viewAny(?Authenticatable $user): bool|Response
    {
        return false;
    }

    /**
     * May the user create one?
     */
    public function create(?Authenticatable $user): bool|Response
    {
        return false;
    }
}
