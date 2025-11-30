# Custom Hybrid Pattern Example

This example demonstrates how to use the Custom Hybrid architecture pattern in KayraPHP, which allows you to mix and match elements from different architecture patterns.

## Directory Structure

```
app/
  Controllers/
    UserController.php
  Domains/
    Product/
      Models/
        Product.php
      Services/
        ProductService.php
  Factories/
    LoggerFactory.php
  Services/
    UserService.php
  Models/
    User.php
  Components/
    Auth/
      AuthManager.php
routes/
  web.php
  api.php
  domains/
    product.php
```

## Configuration

In `config/app.php`:

```php
'architecture' => env('APP_ARCHITECTURE', 'custom'),
```

## Example Code

### MVC Component

```php
<?php

namespace App\Controllers;

use Kayra\Http\Controller;
use Kayra\Http\Request;
use Kayra\Http\Response;
use App\Models\User;
use App\Services\UserService;

class UserController extends Controller
{
    private UserService $userService;
    
    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->userService = new UserService();
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

### Factory-Service Component

```php
<?php

namespace App\Factories;

use Kayra\Foundation\Application;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class LoggerFactory
{
    public static function create(Application $app): Logger
    {
        $logger = new Logger('app');
        $logger->pushHandler(new StreamHandler($app->basePath() . '/storage/logs/app.log', Logger::DEBUG));
        return $logger;
    }
}
```

```php
<?php

namespace App\Services;

use App\Models\User;

class UserService
{
    public function getAllUsers(): array
    {
        return User::all();
    }
    
    public function getUserById(int $id): ?array
    {
        return User::find($id);
    }
}
```

### Domain-Driven Component

```php
<?php

namespace App\Domains\Product\Models;

class Product
{
    private int $id;
    private string $name;
    private float $price;
    
    public function __construct(int $id, string $name, float $price)
    {
        $this->id = $id;
        $this->name = $name;
        $this->price = $price;
    }
    
    public function getId(): int
    {
        return $this->id;
    }
    
    public function getName(): string
    {
        return $this->name;
    }
    
    public function getPrice(): float
    {
        return $this->price;
    }
    
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
        ];
    }
}
```

```php
<?php

namespace App\Domains\Product\Services;

use App\Domains\Product\Models\Product;

class ProductService
{
    public function getAllProducts(): array
    {
        // In a real application, this would fetch products from a database
        $products = [
            new Product(1, 'Product 1', 10.99),
            new Product(2, 'Product 2', 19.99),
        ];
        
        return array_map(fn($product) => $product->toArray(), $products);
    }
    
    public function getProductById(int $id): ?array
    {
        $products = [
            new Product(1, 'Product 1', 10.99),
            new Product(2, 'Product 2', 19.99),
        ];
        
        foreach ($products as $product) {
            if ($product->getId() === $id) {
                return $product->toArray();
            }
        }
        
        return null;
    }
}
```

### Routes

```php
<?php
// web.php
return [
    'GET' => [
        '/users' => 'App\Controllers\UserController@index',
        '/users/{id}' => 'App\Controllers\UserController@show',
    ],
];
```

```php
<?php
// domains/product.php
use App\Domains\Product\Services\ProductService;
use Kayra\Http\Request;
use Kayra\Http\Response;

return [
    'GET' => [
        '/products' => function (Request $request) {
            $productService = new ProductService();
            $products = $productService->getAllProducts();
            return (new Response())->json(['products' => $products]);
        },
        '/products/{id}' => function (Request $request, int $id) {
            $productService = new ProductService();
            $product = $productService->getProductById($id);
            if ($product === null) {
                return (new Response())->json(['error' => 'Product not found'], 404);
            }
            return (new Response())->json(['product' => $product]);
        },
    ],
];
```

## Conclusion

The Custom Hybrid pattern allows you to use the best approach for each part of your application. In this example:

- User management uses a combination of MVC and Factory-Service patterns
- Product management uses the Domain-Driven Design pattern
- Logging uses the Factory-Service pattern

This flexibility allows you to organize your code in a way that best fits your project's needs.