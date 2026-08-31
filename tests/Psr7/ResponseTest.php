<?php

declare(strict_types=1);

namespace Kayra\Tests\Psr7;

use Http\Psr7Test\ResponseIntegrationTest;
use Kayra\Http\Response;
use Psr\Http\Message\ResponseInterface;

final class ResponseTest extends ResponseIntegrationTest
{
    use Psr7TestFactories;

    public function createSubject(): ResponseInterface
    {
        return new Response();
    }
}
