<?php

declare(strict_types=1);

namespace Kayra\Tests\Psr7;

use Http\Psr7Test\UriIntegrationTest;
use Kayra\Http\Uri;
use Psr\Http\Message\UriInterface;

/**
 * PSR-7 conformance for {@see Uri}, using the community integration suite.
 *
 * These tests are written by the PSR-7 maintainers, not by this project, which
 * is the point: they check the spec rather than the implementation's own idea
 * of the spec.
 */
final class UriTest extends UriIntegrationTest
{
    use Psr7TestFactories;

    public function createUri($uri): UriInterface
    {
        return new Uri($uri);
    }
}
