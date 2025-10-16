<?php

namespace Kayra\Logger;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;

class LogManager
{
    protected array $channels = [];
    protected string $default;

    public function __construct(array $config)
    {
        $this->default = $config['default'];
        foreach ($config['channels'] ?? [] as $name => $channel) {
            $this->channels[$name] = $this->createChannel($channel);
        }
    }

    protected function createChannel(array $config)
    {
        if ($config['driver'] === 'async') {
            return new AsyncLogger($config['queue'] ?? '', $config['flush_interval'] ?? 100);
        }
        $monolog = new MonologLogger($config['name'] ?? 'kayra');
        $monolog->pushHandler(new StreamHandler($config['path'] ?? storage_path('logs/kayra.log'), $config['level'] ?? 'debug'));
        return $monolog;
    }

    public function channel(string $name = null): \Psr\Log\LoggerInterface
    {
        $name = $name ?? $this->default;
        return $this->channels[$name] ?? $this->createChannel(['driver' => 'single', 'path' => storage_path('logs/kayra.log')]);
    }

    // PSR-3 proxy
    public function emergency($message, array $context = []): void { $this->channel()->emergency($message, $context); }
    // ... proxy all log methods
}