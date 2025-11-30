# KayraPHP - Performance-First PHP Framework

KayraPHP is a minimal, PSR-compliant PHP framework (PHP 8.4+) focused on performance: precompiled DI, async IO stubs, opcache preload, no runtime reflection.

## Documentation

For full documentation, start the development server and visit http://127.0.0.1:8000/docs or click the "View Documentation" button on the home page.

```bash
php kayra serve
```

## Architecture Patterns

KayraPHP supports multiple architecture patterns that can be selected in the configuration:

### MVC (Model-View-Controller)

The default pattern separates the application into models, views, and controllers:
- **Models**: Represent the data and business logic
- **Views**: Represent the presentation layer
- **Controllers**: Handle user input and coordinate between models and views

### Factory-Service

This pattern separates the application into factories and services:
- **Factories**: Create and configure services
- **Services**: Contain the business logic

### Domain-Driven Design (DDD)

This pattern organizes the application around business domains:
- Each domain has its own models, services, and repositories
- Domains are isolated and communicate through well-defined interfaces

### Custom Hybrid

This pattern allows you to mix and match elements from different architecture patterns:
- Combines aspects of MVC, Factory-Service, and Domain-Driven patterns
- Provides flexibility to organize code in a way that best fits your project's needs

## Configuring Architecture Pattern

You can configure the architecture pattern in the `config/app.php` file:

```php
'architecture' => env('APP_ARCHITECTURE', 'mvc'), // Options: mvc, factory-service, domain-driven, custom
```

You can also set the architecture pattern using the `APP_ARCHITECTURE` environment variable.

## Quick Start

1. **Install Dependencies**:
   ```bash
   composer install
   ```

2. **Generate Application Key**:
   ```bash
   php kayra key:generate
   ```

3. **Start Development Server**:
   ```bash
   php kayra serve
   ```
   This will start a development server at http://127.0.0.1:8000

## Installation

### Requirements
- PHP 8.4 or higher
- Composer
- MySQL 8.0 (for database functionality)
- Redis (for caching, optional)
- Swoole extension (for ultra performance mode, optional)

### Installation Options

#### 1. Via Composer
```bash
composer create-project kayraphp/framework my-project
cd my-project
php kayra key:generate
```

#### 2. Via Docker
```bash
# Clone the repository
git clone https://github.com/kayraphp/framework.git my-project
cd my-project

# Start Docker containers
docker-compose up -d

# Install dependencies and generate key
docker-compose exec php composer install
docker-compose exec php php kayra key:generate
```

## Usage

### CLI Commands

KayraPHP comes with a command-line interface tool that helps you manage your application:

```bash
php kayra [command] [options]
```

Available commands:
- `list`: List all available commands
- `key:generate`: Generate application encryption key
- `cache:clear`: Clear application cache
- `route:list`: List all registered routes
- `serve`: Start development server
  - Options: `--host=127.0.0.1` `--port=8000` `--mode=standard|ultra`
- `make:controller`: Create a new controller
- `make:model`: Create a new model
- `migrate`: Run database migrations
- `container:compile`: Compile dependency injection container

### Development Server

Start the development server in standard mode:
```bash
php kayra serve
```

For better performance with Swoole (requires Swoole extension):
```bash
php kayra serve --mode=ultra
```

## Features

- **Performance-First Design**: Precompiled dependency injection, opcache preload, no runtime reflection
- **Multiple Architecture Patterns**: MVC, Factory-Service, Domain-Driven Design, or Custom Hybrid
- **PSR Compliance**: Follows PHP Standard Recommendations
- **Async I/O Support**: With Swoole extension
- **Modern PHP**: Leverages PHP 8.4+ features
- **Docker Support**: Ready-to-use Docker configuration

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

KayraPHP is open-sourced software licensed under the [MIT license](LICENSE).
