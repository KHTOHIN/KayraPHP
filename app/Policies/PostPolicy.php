<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Post;
use Kayra\Auth\Access\Policy;
use Kayra\Auth\Access\Response;
use Kayra\Auth\Authenticatable;

/**
 * Who may do what to a post.
 *
 * Every method fails closed: an ability with no method here is denied, so
 * adding a new action to the controller without a rule locks it rather than
 * opening it.
 */
final class PostPolicy extends Policy
{
    /** Anyone, signed in or not, may read the list. */
    public function viewAny(?Authenticatable $user): bool
    {
        return true;
    }

    public function view(?Authenticatable $user, Post $post): bool
    {
        return true;
    }

    public function create(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    public function update(?Authenticatable $user, Post $post): Response
    {
        if ($user === null) {
            return Response::deny('Sign in to edit posts.');
        }

        return $post->user_id === $user->getAuthIdentifier()
            ? Response::allow()
            : Response::deny('You can only edit your own posts.');
    }

    public function delete(?Authenticatable $user, Post $post): Response
    {
        return $this->update($user, $post);
    }
}
