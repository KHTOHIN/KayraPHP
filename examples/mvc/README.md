# MVC Pattern Example

This example demonstrates how to use the MVC (Model-View-Controller) architecture pattern in KayraPHP.

## Directory Structure

```
app/
  Controllers/
    UserController.php
  Models/
    User.php
resources/
  views/
    users/
      index.blade.php
      show.blade.php
routes/
  web.php
```

## Configuration

In `config/app.php`:

```php
'architecture' => env('APP_ARCHITECTURE', 'mvc'),
```

## Example Code

### Controller

```php
<?php

namespace App\Controllers;

use Kayra\Http\Controller;
use Kayra\Http\Request;
use Kayra\Http\Response;
use App\Models\User;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::all();
        return $this->view('users.index', ['users' => $users]);
    }

    public function show(Request $request, int $id): Response
    {
        $user = User::find($id);
        return $this->view('users.show', ['user' => $user]);
    }
}
```

### Model

```php
<?php

namespace App\Models;

class User
{
    public static function all(): array
    {
        // In a real application, this would fetch users from a database
        return [
            ['id' => 1, 'name' => 'John Doe'],
            ['id' => 2, 'name' => 'Jane Smith'],
        ];
    }

    public static function find(int $id): ?array
    {
        $users = self::all();
        foreach ($users as $user) {
            if ($user['id'] === $id) {
                return $user;
            }
        }
        return null;
    }
}
```

### Routes

```php
<?php

return [
    'GET' => [
        '/users' => 'App\Controllers\UserController@index',
        '/users/{id}' => 'App\Controllers\UserController@show',
    ],
];
```