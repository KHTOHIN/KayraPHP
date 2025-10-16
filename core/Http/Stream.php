<?php

namespace Kayra\Http;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

class Stream implements StreamInterface
{
    protected $stream;
    protected ?int $size = null;

    public function __construct($stream)
    {
        if (is_string($stream)) {
            $this->stream = fopen('php://temp', 'r+');
            fwrite($this->stream, $stream);
            rewind($this->stream);
        } elseif (is_resource($stream)) {
            $this->stream = $stream;
        } else {
            throw new RuntimeException('Invalid stream provided');
        }
    }

    public function __toString(): string
    {
        try {
            $this->rewind();
            return stream_get_contents($this->stream);
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->detach();
    }

    public function detach()
    {
        $result = $this->stream;
        $this->stream = null;
        $this->size = null;
        return $result;
    }

    public function getSize(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }
        if (!$this->stream) {
            return null;
        }
        $stats = fstat($this->stream);
        return $this->size = ($stats['size'] ?? null);
    }

    public function tell(): int
    {
        $result = ftell($this->stream);
        if ($result === false) {
            throw new RuntimeException('Unable to determine position');
        }
        return $result;
    }

    public function eof(): bool
    {
        return feof($this->stream);
    }

    public function isSeekable(): bool
    {
        $meta = stream_get_meta_data($this->stream);
        return $meta['seekable'] ?? false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        if (!$this->isSeekable()) {
            throw new RuntimeException('Stream not seekable');
        }
        fseek($this->stream, $offset, $whence);
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        $mode = stream_get_meta_data($this->stream)['mode'];
        return strpbrk($mode, 'waxc+') !== false;
    }

    public function write($string): int
    {
        if (!$this->isWritable()) {
            throw new RuntimeException('Stream not writable');
        }
        return fwrite($this->stream, $string);
    }

    public function isReadable(): bool
    {
        $mode = stream_get_meta_data($this->stream)['mode'];
        return strpbrk($mode, 'r+') !== false;
    }

    public function read($length): string
    {
        if (!$this->isReadable()) {
            throw new RuntimeException('Stream not readable');
        }
        return fread($this->stream, $length);
    }

    public function getContents(): string
    {
        return stream_get_contents($this->stream);
    }

    public function getMetadata($key = null)
    {
        $meta = stream_get_meta_data($this->stream);
        return $key === null ? $meta : ($meta[$key] ?? null);
    }
}