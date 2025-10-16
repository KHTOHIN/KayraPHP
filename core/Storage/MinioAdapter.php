<?php

namespace Kayra\Storage;

use GuzzleHttp\Client;
use Kayra\Storage\AdapterInterface;

class MinioAdapter implements AdapterInterface
{
    protected Client $client;
    protected string $bucket;
    protected bool $public;

    public function __construct(array $config)
    {
        $this->client = new Client([
            'endpoint' => $config['endpoint'],
            'use_path_style_endpoint' => true,
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ],
        ]);
        $this->bucket = $config['bucket'];
        $this->public = $config['public'] ?? false;
    }

    public function put(string $path, $contents): bool
    {
        $fullPath = StorageManager::resolvePath('uploads', $path);
        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $fullPath,
                'Body' => $contents,
                'ACL' => $this->public ? 'public-read' : 'private',
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
        // Async: Use Guzzle's promise/async for non-blocking in Swoole
    }

    public function get(string $path)
    {
        $fullPath = StorageManager::resolvePath('uploads', $path);
        try {
            $result = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $fullPath]);
            return stream_get_contents($result['Body']);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function exists(string $path): bool
    {
        $fullPath = StorageManager::resolvePath('uploads', $path);
        try {
            $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $fullPath]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function delete(string $path): bool
    {
        $fullPath = StorageManager::resolvePath('uploads', $path);
        try {
            $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $fullPath]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}