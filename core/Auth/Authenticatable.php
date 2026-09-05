<?php

declare(strict_types=1);

namespace Kayra\Auth;

/**
 * A user that can be authenticated.
 *
 * Kept deliberately small: everything the auth layer needs and nothing about
 * how the user is stored, so a model, a row array, or an LDAP record can all
 * satisfy it.
 */
interface Authenticatable
{
    /**
     * The value that identifies this user — normally the primary key.
     */
    public function getAuthIdentifier(): int|string;

    /**
     * The stored password hash. Never the plain password.
     */
    public function getAuthPassword(): string;

    /**
     * A value that changes when the password does.
     *
     * Sessions store this alongside the id, so changing a password invalidates
     * every other session for that user — which is exactly what a user expects
     * after "someone else has my password".
     */
    public function getAuthPasswordHashSnapshot(): string;
}
