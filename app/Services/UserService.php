<?php

namespace App\Services;

use App\Models\User;

class UserService
{
    public function getUsers(int $limit = 10): array
    {
        $user = new User();
        return $user->all(); // Limit via QueryBuilder in model
    }

    public function createUser(string $name, string $email): int
    {
        $user = new User();
        return $user->create(['name' => $name, 'email' => $email]);
    }
}