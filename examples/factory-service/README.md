# Factory-Service Pattern Example

This example demonstrates how to use the Factory-Service architecture pattern in KayraPHP.

## Directory Structure

```
app/
  Factories/
    UserServiceFactory.php
  Services/
    UserService.php
routes/
  web.php
```

## Configuration

In `config/app.php`:

```php
'architecture' => env('APP_ARCHITECTURE', 'factory-service'),
```

## Example Code

### Factory

```php
<?php

namespace App\Factories;

use App\Services\UserService;
use Kayra\Foundation\Application;

class UserServiceFactory
{
    public static function create(Application $app): UserService
    {
        // In a real application, you would inject dependencies here
        return new UserService();
    }
}
```

### Service

```php
<?php

namespace App\Services;

class UserService
{
    public function getAllUsers(): array
    {
        // In a real application, this would fetch users from a database
        return [
            ['id' => 1, 'name' => 'John Doe'],
            ['id' => 2, 'name' => 'Jane Smith'],
        ];
    }

    public function getUserById(int $id): ?array
    {
        $users = $this->getAllUsers();
        foreach ($users as $user) {
            if ($user['id'] === $id) {
                return $user;
            }
        }
        return null;
    }

    public function handleListUsersRequest(): array
    {
        return [
            'users' => $this->getAllUsers(),
        ];
    }

    public function handleGetUserRequest(int $id): array
    {
        $user = $this->getUserById($id);
        if ($user === null) {
            return ['error' => 'User not found'];
        }
        return ['user' => $user];
    }
}
```

### Routes

```php
<?php

use App\Factories\UserServiceFactory;
use Kayra\Http\Request;
use Kayra\Http\Response;

return [
    'GET' => [
        '/users' => function (Request $request) {
            $userService = UserServiceFactory::create($request->getApp());
            $result = $userService->handleListUsersRequest();
            return (new Response())->json($result);
        },
        '/users/{id}' => function (Request $request, int $id) {
            $userService = UserServiceFactory::create($request->getApp());
            $result = $userService->handleGetUserRequest($id);
            return (new Response())->json($result);
        },
    ],
];
```