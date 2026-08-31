<?php

declare(strict_types=1);

namespace Kayra\Tests;

use Kayra\Container\Container;
use Kayra\Foundation\Application;
use Kayra\Http\Kernel;
use Kayra\Http\Request;
use Kayra\Http\Stream;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Base class for tests that need a booted application.
 *
 * A fresh application is built per test so that no container state, cached
 * config or resolved singleton can leak from one test into the next.
 */
abstract class TestCase extends BaseTestCase
{
    protected Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application(dirname(__DIR__));
        Container::setInstance($this->app);

        $this->app->bootstrap();
        $this->app->config()->set('app.env', 'testing');
        $this->app->boot();
    }

    protected function tearDown(): void
    {
        $this->app->terminate();
        Container::setInstance(null);

        parent::tearDown();
    }

    /**
     * Send a request through the real HTTP kernel.
     *
     * @param array<string, string> $headers
     */
    protected function request(
        string $method,
        string $uri,
        array $headers = [],
        ?string $body = null,
    ): ResponseInterface {
        $request = new Request(
            $method,
            str_starts_with($uri, 'http') ? $uri : 'http://localhost' . $uri,
            $headers,
            $body === null ? null : Stream::of($body),
        );

        $response = $this->app->get(Kernel::class)->handle($request);

        // Mirror what a runtime does between requests.
        $this->app->terminate();

        return $response;
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function postJson(string $uri, array $body, array $headers = []): ResponseInterface
    {
        return $this->request(
            'POST',
            $uri,
            ['Content-Type' => 'application/json'] + $headers,
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Decode a JSON response body.
     *
     * @return array<array-key, mixed>
     */
    protected function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        $this->assertIsArray($decoded, 'Response body was not a JSON object or array.');

        return $decoded;
    }
}
