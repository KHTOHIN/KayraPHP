<?php

declare(strict_types=1);

namespace Kayra\Http;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * PSR-7 stream over a PHP resource.
 */
final class Stream implements StreamInterface
{
    /** @var resource|null */
    private $resource;

    private ?int $size = null;

    private bool $seekable = false;

    private bool $readable = false;

    private bool $writable = false;

    /**
     * Read/write mode lookup tables, from the PSR-7 reference implementations.
     *
     * @var array<string, true>
     */
    private const READABLE = [
        'r' => true, 'r+' => true, 'w+' => true, 'a+' => true, 'x+' => true, 'c+' => true,
        'rb' => true, 'r+b' => true, 'w+b' => true, 'a+b' => true, 'x+b' => true, 'c+b' => true,
        'rt' => true, 'r+t' => true, 'w+t' => true, 'a+t' => true, 'x+t' => true, 'c+t' => true,
    ];

    /** @var array<string, true> */
    private const WRITABLE = [
        'w' => true, 'w+' => true, 'r+' => true, 'a' => true, 'a+' => true, 'x' => true,
        'x+' => true, 'c' => true, 'c+' => true,
        'wb' => true, 'w+b' => true, 'r+b' => true, 'ab' => true, 'a+b' => true, 'xb' => true,
        'x+b' => true, 'cb' => true, 'c+b' => true,
        'wt' => true, 'w+t' => true, 'r+t' => true, 'at' => true, 'a+t' => true, 'xt' => true,
        'x+t' => true, 'ct' => true, 'c+t' => true,
    ];

    /**
     * @param resource $resource
     */
    public function __construct($resource)
    {
        if (!is_resource($resource)) {
            throw new InvalidArgumentException('Stream must be constructed from a resource.');
        }

        $this->resource = $resource;

        $meta = stream_get_meta_data($resource);
        $mode = str_replace(['+b', 'b+'], ['+', '+'], $meta['mode']);

        $this->seekable = $meta['seekable'];
        $this->readable = isset(self::READABLE[$meta['mode']]) || isset(self::READABLE[$mode]);
        $this->writable = isset(self::WRITABLE[$meta['mode']]) || isset(self::WRITABLE[$mode]);
    }

    /**
     * Create an in-memory stream from a string.
     */
    public static function of(string|Stringable $content = ''): self
    {
        $resource = fopen('php://temp', 'r+');

        if ($resource === false) {
            throw new RuntimeException('Unable to open php://temp.');
        }

        $stream = new self($resource);
        $string = (string) $content;

        if ($string !== '') {
            $stream->write($string);
            $stream->rewind();
        }

        // The length is known without asking the filesystem. Recording it here
        // removes an fstat() from every response that reports Content-Length.
        $stream->size = strlen($string);

        return $stream;
    }

    /**
     * Open a file as a stream.
     */
    public static function fromFile(string $path, string $mode = 'r'): self
    {
        $resource = @fopen($path, $mode);

        if ($resource === false) {
            throw new RuntimeException("Unable to open [{$path}] in mode [{$mode}].");
        }

        return new self($resource);
    }

    public function __toString(): string
    {
        try {
            if ($this->seekable) {
                $this->rewind();
            }

            return $this->getContents();
        } catch (Throwable) {
            // __toString must never throw (PSR-7 predates PHP 7.4 relaxations).
            return '';
        }
    }

    public function close(): void
    {
        if ($this->resource !== null) {
            fclose($this->resource);
        }

        $this->detach();
    }

    public function detach()
    {
        $resource = $this->resource;

        $this->resource = null;
        $this->size = null;
        $this->seekable = $this->readable = $this->writable = false;

        return $resource;
    }

    public function getSize(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }

        if ($this->resource === null) {
            return null;
        }

        $stats = fstat($this->resource);

        return $this->size = ($stats['size'] ?? null);
    }

    public function tell(): int
    {
        $this->assertAttached();

        $position = ftell($this->resource);

        if ($position === false) {
            throw new RuntimeException('Unable to determine stream position.');
        }

        return $position;
    }

    public function eof(): bool
    {
        return $this->resource === null || feof($this->resource);
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->assertAttached();

        if (!$this->seekable) {
            throw new RuntimeException('Stream is not seekable.');
        }

        if (fseek($this->resource, $offset, $whence) === -1) {
            throw new RuntimeException("Unable to seek to offset {$offset}.");
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function write(string $string): int
    {
        $this->assertAttached();

        if (!$this->writable) {
            throw new RuntimeException('Stream is not writable.');
        }

        $bytes = fwrite($this->resource, $string);

        if ($bytes === false) {
            throw new RuntimeException('Unable to write to stream.');
        }

        // The cached size is now stale.
        $this->size = null;

        return $bytes;
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function read(int $length): string
    {
        $this->assertAttached();

        if (!$this->readable) {
            throw new RuntimeException('Stream is not readable.');
        }

        if ($length < 0) {
            throw new RuntimeException('Read length cannot be negative.');
        }

        if ($length === 0) {
            return '';
        }

        $data = fread($this->resource, $length);

        if ($data === false) {
            throw new RuntimeException('Unable to read from stream.');
        }

        return $data;
    }

    public function getContents(): string
    {
        $this->assertAttached();

        if (!$this->readable) {
            throw new RuntimeException('Stream is not readable.');
        }

        $contents = stream_get_contents($this->resource);

        if ($contents === false) {
            throw new RuntimeException('Unable to read stream contents.');
        }

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        if ($this->resource === null) {
            return $key === null ? [] : null;
        }

        $meta = stream_get_meta_data($this->resource);

        return $key === null ? $meta : ($meta[$key] ?? null);
    }

    /**
     * @phpstan-assert !null $this->resource
     */
    private function assertAttached(): void
    {
        if ($this->resource === null) {
            throw new RuntimeException('Stream is detached.');
        }
    }
}
