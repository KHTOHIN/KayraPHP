<?php

namespace Kayra\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class AsyncLogger implements LoggerInterface
{
    protected string $queuePath;
    protected array $queue = [];
    protected int $flushInterval;

    public function __construct(string $queuePath = null, int $flushInterval = 100)
    {
        $this->queuePath = $queuePath ?? storage_path('logs/queue.jsonl');
        $this->flushInterval = $flushInterval;
        // Non-blocking: stream_set_blocking(STREAM, false)
    }

    public function emergency($message, array $context = []): void { $this->log(LogLevel::EMERGENCY, $message, $context); }
    public function alert($message, array $context = []): void { $this->log(LogLevel::ALERT, $message, $context); }
    public function critical($message, array $context = []): void { $this->log(LogLevel::CRITICAL, $message, $context); }
    public function error($message, array $context = []): void { $this->log(LogLevel::ERROR, $message, $context); }
    public function warning($message, array $context = []): void { $this->log(LogLevel::WARNING, $message, $context); }
    public function notice($message, array $context = []): void { $this->log(LogLevel::NOTICE, $message, $context); }
    public function info($message, array $context = []): void { $this->log(LogLevel::INFO, $message, $context); }
    public function debug($message, array $context = []): void { $this->log(LogLevel::DEBUG, $message, $context); }

    public function log($level, $message, array $context = []): void
    {
        $this->queue[] = json_encode(['level' => $level, 'message' => $message, 'context' => $context, 'time' => time()]);
        if (count($this->queue) >= $this->flushInterval) {
            $this->flush();
        }
        // Non-blocking: Use fwrite with stream_set_blocking(fopen($this->queuePath, 'a'), false);
        // For Swoole: Queue to worker process or co::fwrite
    }

    public function flush(): void
    {
        if (empty($this->queue)) return;
        $stream = fopen($this->queuePath, 'a');
        stream_set_blocking($stream, false);
        foreach ($this->queue as $entry) {
            fwrite($stream, $entry . "\n");
        }
        fclose($stream);
        $this->queue = [];
    }
}