<?php

declare(strict_types=1);

namespace Kayra\Tests\Feature;

use App\Controllers\Auth\AuthController;
use App\Models\User;
use Kayra\Auth\AuthManager;
use Kayra\Auth\Hasher;
use Kayra\Database\Connection;
use Kayra\Http\Request;
use Kayra\Http\Stream;
use Kayra\RateLimiter\FileRateLimiter;
use Kayra\Session\Session;
use Kayra\Session\SessionHandler;
use Kayra\Tests\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * Per-account rate limiting on the sign-in form.
 *
 * The route's `throttle:10,1` is keyed on the client address, which stops one
 * machine hammering the form and does nothing at all about a thousand machines
 * each making ten polite attempts against the same password. This is the other
 * half: a budget that belongs to the account, wherever the guesses come from.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class LoginThrottleTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private AuthController $controller;

    private FileRateLimiter $limiter;

    private string $limiterPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->config()->set('database.default', 'sqlite');
        $this->app->config()->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->app->config()->set('auth.hashing', ['driver' => 'bcrypt', 'cost' => 4]);

        $this->app->get(Connection::class)->statement(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password TEXT,'
            . ' email_verified_at TEXT, remember_token TEXT, created_at TEXT, updated_at TEXT)',
        );

        $user = new User(['name' => 'Kawsar', 'email' => 'kawsar@example.com']);
        $user->forceFill(['password' => $this->app->get(Hasher::class)->make(self::PASSWORD)]);
        $user->save();

        $session = new Session($this->app->get(SessionHandler::class));
        $session->start();
        $this->app->scopedInstance(Session::class, $session);

        $this->limiterPath = sys_get_temp_dir() . '/kayra_rl_' . bin2hex(random_bytes(4));
        $this->limiter = new FileRateLimiter($this->limiterPath);

        $this->controller = new AuthController(
            $this->app->get(AuthManager::class),
            $this->app->get(Hasher::class),
            $session,
            $this->limiter,
        );
        $this->controller->setApplication($this->app);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->limiterPath . '/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->limiterPath);

        parent::tearDown();
    }

    private function attempt(string $email, string $password): ResponseInterface
    {
        $body = http_build_query(['email' => $email, 'password' => $password]);

        return $this->controller->login(new Request(
            'POST',
            'http://localhost/login',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            Stream::of($body),
        ));
    }

    /**
     * The message the last attempt flashed, if any.
     */
    private function lastError(): string
    {
        $errors = $this->app->get(Session::class)->get('errors');

        if (!is_array($errors)) {
            return '';
        }

        $forEmail = $errors['email'] ?? null;

        if (!is_array($forEmail)) {
            return '';
        }

        $first = $forEmail[0] ?? null;

        return is_string($first) ? $first : '';
    }

    #[Test]
    public function an_account_stops_answering_after_ten_failures(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->attempt('kawsar@example.com', 'wrong-' . $i);
            $this->assertStringContainsString(
                'do not match',
                $this->lastError(),
                "attempt {$i} should still be answered normally",
            );
        }

        $this->attempt('kawsar@example.com', 'wrong-11');

        $this->assertStringContainsString('Too many sign-in attempts', $this->lastError());
    }

    #[Test]
    public function the_lockout_refuses_the_correct_password_too(): void
    {
        // Otherwise the limiter is only an inconvenience: an attacker who
        // guesses right on attempt eleven still gets in.
        for ($i = 1; $i <= 10; $i++) {
            $this->attempt('kawsar@example.com', 'wrong-' . $i);
        }

        $response = $this->attempt('kawsar@example.com', self::PASSWORD);

        $this->assertStringContainsString('Too many sign-in attempts', $this->lastError());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertFalse($this->app->get(AuthManager::class)->check());
    }

    #[Test]
    public function a_correct_password_clears_the_budget(): void
    {
        for ($i = 1; $i <= 9; $i++) {
            $this->attempt('kawsar@example.com', 'wrong-' . $i);
        }

        $response = $this->attempt('kawsar@example.com', self::PASSWORD);
        $this->assertSame('/posts', $response->getHeaderLine('Location'));

        // The failures were somebody guessing; the owner should not inherit
        // what is left of their budget.
        for ($i = 1; $i <= 10; $i++) {
            $this->attempt('kawsar@example.com', 'wrong-again-' . $i);
            $this->assertStringContainsString('do not match', $this->lastError());
        }
    }

    #[Test]
    public function the_budget_belongs_to_one_address_not_to_everyone(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->attempt('kawsar@example.com', 'wrong-' . $i);
        }

        $this->attempt('someone.else@example.com', 'wrong');

        $this->assertStringContainsString('do not match', $this->lastError());
    }

    #[Test]
    public function case_does_not_buy_a_second_budget(): void
    {
        // Addresses are matched case-insensitively in practice, so "KAWSAR@..."
        // must not hand an attacker a fresh set of guesses.
        for ($i = 1; $i <= 10; $i++) {
            $this->attempt('kawsar@example.com', 'wrong-' . $i);
        }

        $this->attempt('KAWSAR@Example.com', 'wrong-again');

        $this->assertStringContainsString('Too many sign-in attempts', $this->lastError());
    }

    #[Test]
    public function an_unregistered_address_is_throttled_the_same_way(): void
    {
        // If only real accounts were counted, the presence of a lockout would
        // itself confirm that an address is registered.
        for ($i = 1; $i <= 10; $i++) {
            $this->attempt('nobody@example.com', 'wrong-' . $i);
        }

        $this->attempt('nobody@example.com', 'wrong-11');

        $this->assertStringContainsString('Too many sign-in attempts', $this->lastError());
    }
}
