<?php

declare(strict_types=1);

namespace Kayra\Tests\Psr7;

use Http\Psr7Test\ServerRequestIntegrationTest;
use Kayra\Http\Request;
use Psr\Http\Message\ServerRequestInterface;

final class ServerRequestTest extends ServerRequestIntegrationTest
{
    use Psr7TestFactories;

    public function createSubject(): ServerRequestInterface
    {
        return new Request('GET', '/', [], null, '1.1', $_SERVER);
    }
}
