<?php

namespace Kayra\Storage;

use Kayra\Storage\AdapterInterface;

class LocalAdapter implements AdapterInterface
{
    protected string $root;
    protected bool $autoFold;
    protected bool $public;

    public function __construct(array $config)
    {
        $this->root = $config['root'];
        $this->autoFold = $config['auto_fold'] ?? false;
        $this->public = $config['public'] ?? false;
    }

    public function put(string $path, $contents): bool
    {
        $fullPath = $this->root . '/' . StorageManager::resolvePath('uploads', $path, $this->autoFold);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $result = file_put_contents($fullPath, $contents) !== false;
        stream_set_blocking(fopen($fullPath, 'r'), false); // Non-blocking hint
        return $result;
        // For Swoole: Use co::fwrite for coroutine non-blocking
    }

    public function get(string $path)
    {
        $fullPath = $this->root . '/' . StorageManager::resolvePath('uploads', $path, $this->autoFold);
        return file_exists($fullPath) ? file_get_contents($fullPath) : null;
    }

    public function exists(string $path): bool
    {
        $fullPath = $this->root . '/' . StorageManager::resolvePath('uploads', $path, $this->autoFold);
        return file_exists($fullPath);
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->root . '/' . StorageManager::resolvePath('uploads', $path, $this->autoFold);
        return unlink($fullPath);
    }
}