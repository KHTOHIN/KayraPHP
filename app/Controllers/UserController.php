<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\UserService;
use Kayra\Http\Controller;
use Kayra\Http\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * Example API controller.
 *
 * Dependencies arrive through the constructor; route parameters arrive as
 * method arguments matched by name. Neither is wired up by hand.
 */
final class UserController extends Controller
{
    public function __construct(private readonly UserService $users)
    {
    }

    public function index(): ResponseInterface
    {
        return $this->json([
            'data' => $this->users->all(),
            'meta' => ['total' => $this->users->count()],
        ]);
    }

    /**
     * The {user} route parameter is injected by name.
     */
    public function show(string $user): ResponseInterface
    {
        $found = $this->users->find((int) $user);

        if ($found === null) {
            abort(404, "User [{$user}] not found.");
        }

        return $this->json(['data' => $found]);
    }

    public function store(Request $request): ResponseInterface
    {
        $name = $request->input('name');

        if (!is_string($name) || trim($name) === '') {
            return $this->json(['message' => 'The name field is required.'], 422);
        }

        return $this->json(['data' => ['id' => 3, 'name' => $name]], 201);
    }

    public function update(string $user, Request $request): ResponseInterface
    {
        return $this->json(['data' => ['id' => (int) $user, 'name' => $request->input('name')]]);
    }

    public function destroy(string $user): ResponseInterface
    {
        return $this->noContent();
    }

    /**
     * Behind the 'auth' middleware, so the token attribute is always present.
     */
    public function me(Request $request): ResponseInterface
    {
        return $this->json([
            'token' => $request->getAttribute('auth.token'),
            'user'  => $this->users->find(1),
        ]);
    }
}
