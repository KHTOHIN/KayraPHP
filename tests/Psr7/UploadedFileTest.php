<?php

declare(strict_types=1);

namespace Kayra\Tests\Psr7;

use Http\Psr7Test\UploadedFileIntegrationTest;
use Kayra\Http\Stream;
use Kayra\Http\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;

final class UploadedFileTest extends UploadedFileIntegrationTest
{
    use Psr7TestFactories;

    public function createSubject(): UploadedFileInterface
    {
        $stream = Stream::of('uploaded contents');

        return new UploadedFile($stream, $stream->getSize(), UPLOAD_ERR_OK, 'test.txt', 'text/plain');
    }
}
