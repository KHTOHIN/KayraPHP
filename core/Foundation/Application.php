<?php

namespace Kayra\Foundation;

class Application
{
    protected string $basePath;
    protected array $bindings = [];
    protected array $instances = [];
    protected bool $booted = false;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /** -------------------------------------------------
     *  Container Methods
     *  ------------------------------------------------- */

    public function bind(string $name, callable $resolver): void
    {
        $this->bindings[$name] = $resolver;
    }

    public function singleton(string $name, callable $resolver): void
    {
        $this->bindings[$name] = $resolver;
        $this->instances[$name] = null; // reserve slot
    }

    public function make(string $name)
    {
        // Return already resolved singleton
        if (array_key_exists($name, $this->instances) && $this->instances[$name] !== null) {
            return $this->instances[$name];
        }

        if (!isset($this->bindings[$name])) {
            throw new \Exception("Service not registered: {$name}");
        }

        $object = ($this->bindings[$name])();

        if (array_key_exists($name, $this->instances)) {
            $this->instances[$name] = $object;
        }

        return $object;
    }

    /**
     * Manually store a shared instance (like Laravel's instance()).
     */
    public function instance(string $name, mixed $object): void
    {
        $this->instances[$name] = $object;
    }

    /** -------------------------------------------------
     *  Boot Framework
     *  ------------------------------------------------- */
    public function boot(): void
    {
        if ($this->booted) return;

        // Core bindings
        $this->singleton('app', fn() => $this);
        $this->singleton(\Kayra\Http\Request::class, fn() => \Kayra\Http\Request::capture());
        $this->singleton(\Kayra\Http\Response::class, fn() => new \Kayra\Http\Response());
        $this->singleton(\Kayra\Http\Router::class, fn() => new \Kayra\Http\Router($this));

        $this->booted = true;
    }
}