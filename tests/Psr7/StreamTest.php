<?php

declare(strict_types=1);

namespace Kayra\Tests\Psr7;

use Http\Psr7Test\StreamIntegrationTest;
use Kayra\Http\Stream;
use Psr\Http\Message\StreamInterface;

final class StreamTest extends StreamIntegrationTest
{
    use Psr7TestFactories;

    public function createStream($data): StreamInterface
    {
        if ($data instanceof StreamInterface) {
            return $data;
        }

        if (is_resource($data)) {
            return new Stream($data);
        }

        return Stream::of((string) $data);
    }
}
