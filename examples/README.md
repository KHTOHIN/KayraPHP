# KayraPHP Architecture Pattern Examples

This directory contains examples of how to use the different architecture patterns supported by KayraPHP.

## Available Patterns

KayraPHP supports the following architecture patterns:

1. [MVC (Model-View-Controller)](mvc/README.md)
2. [Factory-Service](factory-service/README.md)
3. [Domain-Driven Design (DDD)](domain-driven/README.md)
4. [Custom Hybrid](custom-hybrid/README.md)

## Configuring Architecture Pattern

You can configure the architecture pattern in the `config/app.php` file:

```php
'architecture' => env('APP_ARCHITECTURE', 'mvc'), // Options: mvc, factory-service, domain-driven, custom
```

You can also set the architecture pattern using the `APP_ARCHITECTURE` environment variable.

## Pattern Comparison

| Pattern | Pros | Cons | Best For |
|---------|------|------|----------|
| MVC | Simple, widely understood, clear separation of concerns | Can lead to fat controllers, tight coupling between models and views | Simple applications, CRUD operations, traditional web apps |
| Factory-Service | Clear separation of concerns, promotes dependency injection, testable | More complex than MVC, requires more files | Applications with complex business logic, microservices |
| Domain-Driven | Focuses on business domains, isolates domains, promotes bounded contexts | Most complex, steep learning curve | Large enterprise applications, complex business domains |
| Custom Hybrid | Flexible, can use the best approach for each part of the application | Requires careful planning, can lead to inconsistency | Applications with varying complexity across different features |

## Getting Started

1. Choose an architecture pattern that best fits your project's needs
2. Configure the pattern in `config/app.php`
3. Follow the example for your chosen pattern to structure your application

Each example includes:
- Directory structure
- Configuration
- Example code
- Explanation of how the pattern works

## Further Reading

- [MVC Pattern](https://en.wikipedia.org/wiki/Model%E2%80%93view%E2%80%93controller)
- [Factory Pattern](https://en.wikipedia.org/wiki/Factory_method_pattern)
- [Service Pattern](https://en.wikipedia.org/wiki/Service_layer_pattern)
- [Domain-Driven Design](https://en.wikipedia.org/wiki/Domain-driven_design)