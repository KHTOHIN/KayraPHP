<?php

declare(strict_types=1);

namespace Kayra\Tests\Psr7;

use Kayra\Http\Stream;
use Kayra\Http\UploadedFile;
use Kayra\Http\Uri;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/**
 * Tells the PSR-7 integration suite how to build KayraPHP objects.
 *
 * The suite discovers a PSR-17 factory by convention; supplying these hooks
 * directly keeps the tests independent of any factory package.
 */
trait Psr7TestFactories
{
    protected function buildUri($uri): UriInterface
    {
        return $uri instanceof UriInterface ? $uri : new Uri((string) $uri);
    }

    protected function buildStream($data): StreamInterface
    {
        if ($data instanceof StreamInterface) {
            return $data;
        }

        if (is_resource($data)) {
            return new Stream($data);
        }

        return Stream::of((string) $data);
    }

    protected function buildUploadableFile($data): UploadedFileInterface
    {
        $stream = $this->buildStream($data);

        return new UploadedFile($stream, $stream->getSize(), UPLOAD_ERR_OK, 'test.txt', 'text/plain');
    }
}
