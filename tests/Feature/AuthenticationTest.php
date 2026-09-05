<?php

declare(strict_types=1);

namespace Kayra\Tests\Feature;

use App\Models\User;
use Kayra\Auth\AuthManager;
use Kayra\Auth\Hasher;
use Kayra\Auth\SessionGuard;
use Kayra\Database\Connection;
use Kayra\Http\Middleware\Authenticate;
use Kayra\Http\Response;
use Kayra\Pipeline\CallableHandler;
use Kayra\Session\Session;
use Kayra\Tests\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;

/**
 * The auth layer wired through the real application container.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class AuthenticationTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        // An in-memory database per test; the schema mirrors the auth migration.
        $this->app->config()->set('database.default', 'sqlite');
        $this->app->config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        // Keep the suite fast; production uses cost 12.
        $this->app->config()->set('auth.hashing', ['driver' => 'bcrypt', 'cost' => 4]);

        $this->db = $this->app->get(Connection::class);

        $this->db->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password TEXT, email_verified_at TEXT, remember_token TEXT, created_at TEXT, updated_at TEXT)');
        $this->db->statement('CREATE TABLE api_tokens (id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, token TEXT, expires_at TEXT, created_at TEXT, updated_at TEXT)');
    }

    private function makeUser(string $password = 'correct-horse'): User
    {
        $user = new User(['name' => 'Kawsar', 'email' => 'k@test']);
        $user->forceFill(['password' => $this->app->get(Hasher::class)->make($password)]);
        $user->save();

        return $user;
    }

    #[Test]
    public function the_container_wires_the_configured_guard(): void
    {
        $this->app->scopedInstance(Session::class, $this->startedSession());

        $this->assertInstanceOf(SessionGuard::class, $this->app->get(AuthManager::class)->guard());
    }

    #[Test]
    public function a_user_can_log_in_through_the_manager(): void
    {
        $this->makeUser();
        $this->app->scopedInstance(Session::class, $this->startedSession());

        $auth = $this->app->get(AuthManager::class);

        $this->assertFalse($auth->check());
        $this->assertTrue($auth->session()->attempt(['email' => 'k@test', 'password' => 'correct-horse']));
        $this->assertTrue($auth->check());
        $this->assertSame('k@test', $auth->user()?->getAttributes()['email']);
    }

    #[Test]
    public function guards_are_request_scoped_not_shared_between_requests(): void
    {
        // A guard holds the current user. Caching it as a singleton would serve
        // one user's identity to the next request on a long-running worker.
        $this->app->scopedInstance(Session::class, $this->startedSession());
        $first = $this->app->get(AuthManager::class);

        $this->app->terminate();

        $this->app->scopedInstance(Session::class, $this->startedSession());

        $this->assertNotSame($first, $this->app->get(AuthManager::class));
    }

    #[Test]
    public function an_api_client_gets_401_and_a_browser_gets_a_redirect(): void
    {
        // One middleware serves both, decided by content negotiation rather
        // than by the route.
        $this->app->scopedInstance(Session::class, $this->startedSession());

        $middleware = new Authenticate($this->app->get(AuthManager::class));
        $next = new CallableHandler(static fn (): Response => Response::text('reached'));

        $browser = $middleware->process($this->makeRequest('GET', '/private'), $next);
        $this->assertSame(302, $browser->getStatusCode());
        $this->assertSame('/login', $browser->getHeaderLine('Location'));

        try {
            $middleware->process(
                $this->makeRequest('GET', '/private', ['Accept' => 'application/json']),
                $next,
            );
            $this->fail('Expected a 401 for a JSON client.');
        } catch (\Kayra\Exceptions\HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('Bearer', $e->getHeaders()['WWW-Authenticate']);
        }
    }

    #[Test]
    public function an_authenticated_request_reaches_the_action_with_the_user_attached(): void
    {
        $user = $this->makeUser();
        $session = $this->startedSession();
        $this->app->scopedInstance(Session::class, $session);

        $auth = $this->app->get(AuthManager::class);
        $auth->session()->login($user);

        $seen = null;

        $response = (new Authenticate($auth))->process(
            $this->makeRequest('GET', '/private'),
            new CallableHandler(function ($request) use (&$seen): Response {
                $seen = $request->getAttribute('auth.id');

                return Response::text('reached');
            }),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($user->getKey(), $seen);
    }

    #[Test]
    public function the_hasher_uses_the_configured_cost(): void
    {
        $this->app->config()->set('auth.hashing', ['driver' => 'bcrypt', 'cost' => 5]);
        $this->app->forgetInstance(Hasher::class);

        $info = $this->app->get(Hasher::class)->info($this->app->get(Hasher::class)->make('x'));

        $this->assertSame(5, $info['options']['cost'] ?? null);
    }

    /**
     * @param array<string, string> $headers
     */
    private function makeRequest(string $method, string $uri, array $headers = []): \Kayra\Http\Request
    {
        return new \Kayra\Http\Request($method, 'http://localhost' . $uri, $headers);
    }

    private function startedSession(): Session
    {
        $session = new Session(
            $this->app->get(\Kayra\Session\SessionHandler::class),
            '',
        );
        $session->start();

        return $session;
    }
}
