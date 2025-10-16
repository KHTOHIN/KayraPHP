<?php

namespace Kayra\Storage;

class StorageManager
{
    protected array $disks = [];
    protected string $default;

    public function __construct(array $config)
    {
        $this->default = $config['default'];
        foreach ($config['disks'] as $name => $diskConfig) {
            $this->disks[$name] = $this->createDriver($diskConfig);
        }
    }

    protected function createDriver(array $config)
    {
        return match ($config['driver']) {
            'local' => new LocalAdapter($config),
            'minio' => new MinioAdapter($config),
            default => throw new \Exception('Unsupported storage driver'),
        };
    }

    public function disk(string $name = null): AdapterInterface
    {
        $name = $name ?? $this->default;
        return $this->disks[$name];
    }

    public static function resolvePath(string $type, string $filename, bool $autoFold = true): string
    {
        if (!$autoFold) return $filename;
        $date = date('Y/m/d');
        return "{$type}/{$date}/" . basename($filename);
    }
}

// Interface for async swap
interface AdapterInterface {
    public function put(string $path, $contents): bool;
    public function get(string $path);
    public function exists(string $path): bool;
    public function delete(string $path): bool;
    // Async: Implement with Swoole\Coroutine\System::exec or Guzzle async
}