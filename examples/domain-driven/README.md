# Domain-Driven Design (DDD) Pattern Example

This example demonstrates how to use the Domain-Driven Design architecture pattern in KayraPHP.

## Directory Structure

```
app/
  Domains/
    User/
      Models/
        User.php
      Repositories/
        UserRepository.php
      Services/
        UserService.php
      Controllers/
        UserController.php
routes/
  domains/
    user.php
```

## Configuration

In `config/app.php`:

```php
'architecture' => env('APP_ARCHITECTURE', 'domain-driven'),
```

## Example Code

### Domain Model

```php
<?php

namespace App\Domains\User\Models;

class User
{
    private int $id;
    private string $name;
    
    public function __construct(int $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
    }
    
    public function getId(): int
    {
        return $this->id;
    }
    
    public function getName(): string
    {
        return $this->name;
    }
    
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
```

### Domain Repository

```php
<?php

namespace App\Domains\User\Repositories;

use App\Domains\User\Models\User;

class UserRepository
{
    public function findAll(): array
    {
        // In a real application, this would fetch users from a database
        return [
            new User(1, 'John Doe'),
            new User(2, 'Jane Smith'),
        ];
    }
    
    public function findById(int $id): ?User
    {
        $users = $this->findAll();
        foreach ($users as $user) {
            if ($user->getId() === $id) {
                return $user;
            }
        }
        return null;
    }
}
```

### Domain Service

```php
<?php

namespace App\Domains\User\Services;

use App\Domains\User\Repositories\UserRepository;

class UserService
{
    private UserRepository $userRepository;
    
    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }
    
    public function getAllUsers(): array
    {
        $users = $this->userRepository->findAll();
        return array_map(fn($user) => $user->toArray(), $users);
    }
    
    public function getUserById(int $id): ?array
    {
        $user = $this->userRepository->findById($id);
        return $user ? $user->toArray() : null;
    }
}
```

### Domain Controller

```php
<?php

namespace App\Domains\User\Controllers;

use App\Domains\User\Repositories\UserRepository;
use App\Domains\User\Services\UserService;
use Kayra\Http\Controller;
use Kayra\Http\Request;
use Kayra\Http\Response;

class UserController extends Controller
{
    private UserService $userService;
    
    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $userRepository = new UserRepository();
        $this->userService = new UserService($userRepository);
    }
    
    public function index(Request $request): Response
    {
        $users = $this->userService->getAllUsers();
        return $this->json(['users' => $users]);
    }
    
    public function show(Request $request, int $id): Response
    {
        $user = $this->userService->getUserById($id);
        if ($user === null) {
            return $this->json(['error' => 'User not found'], 404);
        }
        return $this->json(['user' => $user]);
    }
}
```

### Domain Routes

```php
<?php

return [
    'GET' => [
        '/users' => 'App\Domains\User\Controllers\UserController@index',
        '/users/{id}' => 'App\Domains\User\Controllers\UserController@show',
    ],
];
```