<?php

declare(strict_types=1);

namespace Kayra\Tests\Feature;

use App\Services\UserService;
use Kayra\Http\Kernel;
use Kayra\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Exercises the full stack: kernel, middleware, router, container, view engine.
 */
final class HttpKernelTest extends TestCase
{
    #[Test]
    public function it_renders_an_html_page_through_the_template_engine(): void
    {
        $response = $this->request('GET', '/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<!doctype html>', (string) $response->getBody());
    }

    #[Test]
    public function it_applies_global_security_middleware(): void
    {
        $response = $this->request('GET', '/');

        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    #[Test]
    public function it_sets_content_length(): void
    {
        $response = $this->request('GET', '/health');

        $this->assertSame(
            (string) $response->getBody()->getSize(),
            $response->getHeaderLine('Content-Length'),
        );
    }

    #[Test]
    public function a_closure_returning_an_array_becomes_json(): void
    {
        $response = $this->request('GET', '/api/v1/ping');

        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertTrue($this->json($response)['pong']);
    }

    #[Test]
    public function controller_dependencies_are_injected(): void
    {
        $response = $this->request('GET', '/api/v1/users');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $this->json($response)['meta']['total']);
    }

    #[Test]
    public function route_parameters_are_injected_by_name(): void
    {
        $response = $this->request('GET', '/api/v1/users/1');

        $this->assertSame('Kawsar Hamid', $this->json($response)['data']['name']);
    }

    #[Test]
    public function abort_from_a_controller_produces_the_right_status(): void
    {
        $this->assertSame(404, $this->request('GET', '/api/v1/users/99')->getStatusCode());
    }

    #[Test]
    public function a_json_request_body_is_readable_through_input(): void
    {
        $response = $this->postJson('/api/v1/users', ['name' => 'New User']);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('New User', $this->json($response)['data']['name']);
    }

    #[Test]
    public function middleware_can_reject_a_request(): void
    {
        $response = $this->request('GET', '/api/v1/me');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    }

    #[Test]
    public function attributes_added_by_middleware_reach_the_action(): void
    {
        // Regression: PSR-7 messages are immutable, so the request the action
        // receives must be re-shared into the container at dispatch time.
        $response = $this->request('GET', '/api/v1/me', ['Authorization' => 'Bearer tok-123']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('tok-123', $this->json($response)['token']);
    }

    #[Test]
    public function an_unknown_route_returns_404(): void
    {
        $this->assertSame(404, $this->request('GET', '/nope')->getStatusCode());
    }

    #[Test]
    public function errors_are_rendered_as_json_when_the_client_asks_for_json(): void
    {
        $response = $this->request('GET', '/nope', ['Accept' => 'application/json']);

        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertArrayHasKey('message', $this->json($response));
    }

    #[Test]
    public function a_wrong_method_returns_405_with_an_allow_header(): void
    {
        $response = $this->request('DELETE', '/health');

        $this->assertSame(405, $response->getStatusCode());
        $this->assertNotSame('', $response->getHeaderLine('Allow'));
    }

    #[Test]
    public function head_requests_carry_no_body(): void
    {
        $response = $this->request('HEAD', '/health');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function a_204_response_has_neither_body_nor_content_type(): void
    {
        $response = $this->request('DELETE', '/api/v1/users/1');

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertFalse($response->hasHeader('Content-Type'));
    }

    #[Test]
    public function scoped_services_are_discarded_between_requests(): void
    {
        $first = $this->app->get(UserService::class);
        $this->assertSame($first, $this->app->get(UserService::class));

        $this->app->terminate();

        $this->assertNotSame($first, $this->app->get(UserService::class));
    }

    #[Test]
    public function singletons_survive_between_requests(): void
    {
        $kernel = $this->app->get(Kernel::class);

        $this->app->terminate();

        $this->assertSame($kernel, $this->app->get(Kernel::class));
    }

    #[Test]
    public function the_kernel_handles_many_requests_in_one_process(): void
    {
        // Approximates what a Swoole worker does over its lifetime.
        for ($i = 0; $i < 30; $i++) {
            $this->assertSame(200, $this->request('GET', '/api/v1/users/' . (($i % 2) + 1))->getStatusCode());
        }
    }

    #[Test]
    public function debug_is_forced_off_in_production_even_when_configured_on(): void
    {
        $this->app->config()->set('app.env', 'production');
        $this->app->config()->set('app.debug', true);

        $this->assertFalse($this->app->isDebug());
    }

    #[Test]
    public function production_error_pages_do_not_leak_internals(): void
    {
        $this->app->config()->set('app.env', 'production');
        $this->app->config()->set('app.debug', true);

        $body = (string) $this->request('GET', '/nope', ['Accept' => 'application/json'])->getBody();

        $this->assertStringNotContainsString('NotFoundHttpException', $body);
        $this->assertStringNotContainsString('trace', $body);
    }
}
