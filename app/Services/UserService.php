<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Example service.
 *
 * Business logic lives here rather than in controllers, so it can be tested
 * without an HTTP request and reused from the CLI or a queue worker.
 */
final class UserService
{
    /** @var list<array{id: int, name: string, email: string}> */
    private array $users = [
        ['id' => 1, 'name' => 'Kawsar Hamid', 'email' => 'kawsar@example.com'],
        ['id' => 2, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
    ];

    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    public function all(): array
    {
        return $this->users;
    }

    /**
     * @return array{id: int, name: string, email: string}|null
     */
    public function find(int $id): ?array
    {
        foreach ($this->users as $user) {
            if ($user['id'] === $id) {
                return $user;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->users);
    }
}
