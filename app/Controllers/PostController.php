<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Post;
use Kayra\Auth\AuthManager;
use Kayra\Exceptions\ValidationException;
use Kayra\Http\Controller;
use Kayra\Http\Request;
use Kayra\Session\Session;
use Kayra\Validation\Validated;
use Kayra\Validation\Validator;
use Psr\Http\Message\ResponseInterface;

/**
 * CRUD over posts, protected by {@see \App\Policies\PostPolicy}.
 *
 * Three habits worth copying:
 *
 *  - `Post $post` in the signature is all it takes to receive the record: the
 *    binding middleware fetched it, and a missing id already became a 404
 *    before this class was constructed.
 *  - Every mutation calls `authorize()`, and the policy -- not this controller
 *    -- decides. The controller never compares user ids itself.
 *  - Ownership comes from the authenticated user, never from request input,
 *    which is why `user_id` is absent from the model's $fillable.
 */
final class PostController extends Controller
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Session $session,
    ) {
    }

    public function index(): ResponseInterface
    {
        $this->authorize('viewAny', Post::class);

        return $this->view('posts.index', [
            // Eager-loaded: without `with`, rendering N posts would run N extra
            // queries for their authors.
            'posts' => Post::with('author')->orderBy('created_at', 'DESC')->get(),
        ]);
    }

    public function create(): ResponseInterface
    {
        $this->authorize('create', Post::class);

        return $this->view('posts.create');
    }

    public function store(Request $request): ResponseInterface
    {
        $this->authorize('create', Post::class);

        try {
            $input = $this->validated($request);
        } catch (ValidationException $e) {
            return $this->backTo('/posts/create', $e, $request);
        }

        $post = new Post($input->only(['title', 'body']));
        // Assigned here, from the session -- never accepted as input.
        $post->forceFill(['user_id' => $this->auth->id()]);
        $post->save();

        $this->session->flash('status', 'Post published.');

        return $this->redirect('/posts');
    }

    public function edit(Post $post): ResponseInterface
    {
        $this->authorize('update', $post);

        return $this->view('posts.edit', ['post' => $post]);
    }

    public function update(Post $post, Request $request): ResponseInterface
    {
        $this->authorize('update', $post);

        try {
            $input = $this->validated($request);
        } catch (ValidationException $e) {
            return $this->backTo('/posts/' . $post->getRouteKey() . '/edit', $e, $request);
        }

        $post->update($input->only(['title', 'body']));

        $this->session->flash('status', 'Post updated.');

        return $this->redirect('/posts');
    }

    public function destroy(Post $post): ResponseInterface
    {
        $this->authorize('delete', $post);

        $post->delete();

        $this->session->flash('status', 'Post deleted.');

        return $this->redirect('/posts');
    }

    /* --------------------------------------------------------------------
     | Shared between store() and update()
     * -------------------------------------------------------------------- */

    /**
     * @throws ValidationException
     */
    private function validated(Request $request): Validated
    {
        return Validator::make($request->all(), [
            'title' => 'required|string|min:3|max:200',
            'body'  => 'required|string|min:3|max:10000',
        ])->safe();
    }

    /**
     * Redirect back to a form with the errors and the input that failed.
     */
    private function backTo(string $to, ValidationException $e, Request $request): ResponseInterface
    {
        $this->session->flash('errors', $e->errors);
        $this->session->flash('old', $request->only(['title', 'body']));

        return $this->redirect($to);
    }
}
