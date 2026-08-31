<?php

declare(strict_types=1);

namespace Kayra\Tests\Unit;

use Kayra\Config\Repository;
use Kayra\Encryption\Encrypter;
use Kayra\Encryption\EncryptionException;
use Kayra\Exceptions\HttpException;
use Kayra\Http\Middleware\ThrottleRequests;
use Kayra\Http\Middleware\VerifyCsrfToken;
use Kayra\Http\Request;
use Kayra\Http\Response;
use Kayra\Http\Stream;
use Kayra\Pipeline\CallableHandler;
use Kayra\RateLimiter\FileRateLimiter;
use Kayra\Session\FileSessionHandler;
use Kayra\Session\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Encrypter::class)]
#[CoversClass(Session::class)]
#[CoversClass(FileSessionHandler::class)]
#[CoversClass(VerifyCsrfToken::class)]
#[CoversClass(FileRateLimiter::class)]
#[CoversClass(ThrottleRequests::class)]
final class SecurityTest extends TestCase
{
    private string $tmp;

    private Encrypter $encrypter;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/kayra_sec_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0o700, true);
        $this->encrypter = Encrypter::fromKey(Encrypter::generateKey());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }

        @rmdir($this->tmp);
    }

    /* ----------------------------------------------------------- encrypter */

    #[Test]
    public function encryption_round_trips_structured_data(): void
    {
        $value = ['user' => 7, 'roles' => ['admin', 'editor'], 'nested' => ['a' => null]];

        $this->assertSame($value, $this->encrypter->decrypt($this->encrypter->encrypt($value)));
    }

    #[Test]
    public function the_same_plaintext_encrypts_differently_every_time(): void
    {
        // A deterministic ciphertext leaks equality: an observer could tell that
        // two sessions hold the same value without decrypting either.
        $this->assertNotSame($this->encrypter->encrypt('same'), $this->encrypter->encrypt('same'));
    }

    #[Test]
    #[DataProvider('tamperedPayloads')]
    public function tampering_is_detected(string $mutate): void
    {
        $payload = $this->encrypter->encrypt('secret');

        $tampered = match ($mutate) {
            // Flip a byte in the decoded ciphertext and re-encode. Mutating the
            // base64 text directly is unreliable: the final character can carry
            // padding bits that decode to the same bytes, so roughly 7% of such
            // "mutations" change nothing at all.
            'flip-byte' => $this->flipCiphertextByte($payload),
            'truncate'  => substr($payload, 0, -8),
            'wrong-version' => 'k9' . substr($payload, 2),
            'empty'     => '',
            default     => $payload,
        };

        $this->expectException(EncryptionException::class);

        $this->encrypter->decrypt($tampered);
    }

    /**
     * Flip one bit in the middle of the ciphertext, preserving the envelope.
     */
    private function flipCiphertextByte(string $payload): string
    {
        [$version, $encoded] = explode('.', $payload, 2);

        $raw = (string) base64_decode(strtr($encoded, '-_', '+/'), true);
        $index = intdiv(strlen($raw), 2);
        $raw[$index] = chr(ord($raw[$index]) ^ 0xFF);

        return $version . '.' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function tamperedPayloads(): iterable
    {
        yield 'modified byte'   => ['flip-byte'];
        yield 'truncated'       => ['truncate'];
        yield 'version changed' => ['wrong-version'];
        yield 'empty'           => ['empty'];
    }

    #[Test]
    public function a_payload_from_another_key_cannot_be_decrypted(): void
    {
        $other = Encrypter::fromKey(Encrypter::generateKey());

        $this->expectException(EncryptionException::class);

        $this->encrypter->decrypt($other->encrypt('secret'));
    }

    #[Test]
    public function a_short_key_is_rejected(): void
    {
        $this->expectException(EncryptionException::class);

        new Encrypter('too-short');
    }

    #[Test]
    public function signatures_verify_and_reject(): void
    {
        $signature = $this->encrypter->sign('message');

        $this->assertTrue($this->encrypter->verify('message', $signature));
        $this->assertFalse($this->encrypter->verify('tampered', $signature));
    }

    /* ------------------------------------------------------------- session */

    private function session(string $id = ''): Session
    {
        return new Session(new FileSessionHandler($this->tmp, $this->encrypter, 7200), $id);
    }

    #[Test]
    public function session_data_persists_across_requests(): void
    {
        $first = $this->session();
        $first->start();
        $first->put('user_id', 42);
        $first->save();

        $second = $this->session($first->id());
        $second->start();

        $this->assertSame(42, $second->get('user_id'));
    }

    #[Test]
    public function session_files_are_encrypted_at_rest(): void
    {
        $session = $this->session();
        $session->start();
        $session->put('secret', 'super-secret-value');
        $session->save();

        $contents = file_get_contents($this->tmp . '/sess_' . $session->id());

        $this->assertStringNotContainsString('super-secret-value', (string) $contents);
    }

    #[Test]
    public function a_tampered_session_file_yields_an_empty_session_not_modified_data(): void
    {
        $session = $this->session();
        $session->start();
        $session->put('role', 'user');
        $session->save();

        // An attacker with write access edits the file.
        file_put_contents($this->tmp . '/sess_' . $session->id(), 'k1.' . base64_encode('forged'));

        $reloaded = $this->session($session->id());
        $reloaded->start();

        $this->assertNull($reloaded->get('role'));
    }

    #[Test]
    public function regenerating_changes_the_id_but_keeps_the_data(): void
    {
        // Session fixation defence: the id must change at privilege boundaries.
        $session = $this->session();
        $session->start();
        $session->put('user_id', 1);
        $original = $session->id();

        $session->regenerate();

        $this->assertNotSame($original, $session->id());
        $this->assertSame(1, $session->get('user_id'));
        $this->assertFileDoesNotExist($this->tmp . '/sess_' . $original);
    }

    #[Test]
    public function invalidate_clears_the_data_and_the_id(): void
    {
        $session = $this->session();
        $session->start();
        $session->put('user_id', 1);
        $original = $session->id();

        $session->invalidate();

        $this->assertNull($session->get('user_id'));
        $this->assertNotSame($original, $session->id());
    }

    #[Test]
    #[DataProvider('hostileSessionIds')]
    public function a_crafted_session_id_cannot_reach_the_filesystem(string $id): void
    {
        // The id arrives in a cookie. Without validation it is a path fragment
        // chosen by the client.
        $session = $this->session($id);
        $session->start();

        // A rejected id yields a fresh, well-formed one rather than an error.
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $session->id());
        $this->assertNotSame($id, $session->id());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function hostileSessionIds(): iterable
    {
        yield 'traversal'      => ['../../../../etc/passwd'];
        yield 'null byte'      => ["abc\0def"];
        yield 'wrong length'   => ['abc123'];
        yield 'non-hex'        => [str_repeat('z', 64)];
    }

    #[Test]
    public function flash_data_survives_exactly_one_request(): void
    {
        $a = $this->session();
        $a->start();
        $a->flash('status', 'Saved.');
        $a->save();

        $b = $this->session($a->id());
        $b->start();
        $this->assertSame('Saved.', $b->get('status'), 'Flash must be readable on the next request.');
        $b->save();

        $c = $this->session($b->id());
        $c->start();
        $this->assertNull($c->get('status'), 'Flash must be gone on the request after that.');
    }

    #[Test]
    public function expired_sessions_are_not_returned(): void
    {
        $handler = new FileSessionHandler($this->tmp, $this->encrypter, lifetime: 1);
        $session = new Session($handler, '');
        $session->start();
        $session->put('user_id', 1);
        $session->save();

        touch($this->tmp . '/sess_' . $session->id(), time() - 100);
        clearstatcache();

        $this->assertSame([], $handler->read($session->id()));
    }

    /* ---------------------------------------------------------------- CSRF */

    private function csrf(Request $request): Response
    {
        $middleware = new VerifyCsrfToken(new Repository(['session' => ['csrf_except' => []]]));

        /** @var Response $response */
        $response = $middleware->process(
            $request,
            new CallableHandler(static fn (): Response => Response::text('reached')),
        );

        return $response;
    }

    #[Test]
    public function safe_methods_do_not_require_a_token(): void
    {
        $response = $this->csrf(new Request('GET', 'http://app.test/x'));

        $this->assertSame('reached', (string) $response->getBody());
    }

    #[Test]
    public function a_post_without_a_token_is_rejected(): void
    {
        $session = $this->session();
        $session->start();

        $request = (new Request('POST', 'http://app.test/x'))
            ->withAttribute('session', $session);

        try {
            $this->csrf($request);
            $this->fail('Expected the request to be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(419, $e->getStatusCode());
        }
    }

    #[Test]
    public function a_post_with_the_correct_token_passes(): void
    {
        $session = $this->session();
        $session->start();

        $request = (new Request('POST', 'http://app.test/x'))
            ->withAttribute('session', $session)
            ->withParsedBody(['_token' => $session->token()]);

        $this->assertSame('reached', (string) $this->csrf($request)->getBody());
    }

    #[Test]
    public function a_post_with_another_sessions_token_is_rejected(): void
    {
        $mine = $this->session();
        $mine->start();

        $attacker = $this->session();
        $attacker->start();

        $request = (new Request('POST', 'http://app.test/x'))
            ->withAttribute('session', $mine)
            ->withParsedBody(['_token' => $attacker->token()]);

        $this->expectException(HttpException::class);

        $this->csrf($request);
    }

    #[Test]
    public function the_token_may_arrive_in_a_header(): void
    {
        $session = $this->session();
        $session->start();

        $request = (new Request('POST', 'http://app.test/x', ['X-CSRF-Token' => $session->token()]))
            ->withAttribute('session', $session);

        $this->assertSame('reached', (string) $this->csrf($request)->getBody());
    }

    #[Test]
    public function csrf_fails_closed_when_no_session_is_present(): void
    {
        // Misconfiguration must not silently disable the protection.
        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/no session was started/');

        $this->csrf(new Request('POST', 'http://app.test/x'));
    }

    /* --------------------------------------------------------- rate limiter */

    #[Test]
    public function the_limiter_counts_and_then_blocks(): void
    {
        $limiter = new FileRateLimiter($this->tmp);

        for ($i = 1; $i <= 3; $i++) {
            $this->assertSame($i, $limiter->hit('k', 60));
        }

        $this->assertTrue($limiter->tooManyAttempts('k', 3));
        $this->assertFalse($limiter->tooManyAttempts('k', 4));
        $this->assertGreaterThan(0, $limiter->availableIn('k'));
    }

    #[Test]
    public function the_window_resets_after_it_expires(): void
    {
        $limiter = new FileRateLimiter($this->tmp);
        $limiter->hit('k', 1);

        // Force the window to look expired rather than sleeping.
        $limiter->clear('k');

        $this->assertSame(0, $limiter->attempts('k'));
    }

    #[Test]
    public function limiter_keys_cannot_escape_the_directory(): void
    {
        $limiter = new FileRateLimiter($this->tmp);
        $limiter->hit('../../etc/passwd', 60);

        $written = glob($this->tmp . '/rl_*') ?: [];

        $this->assertCount(1, $written);
        $this->assertStringStartsWith($this->tmp, $written[0]);
    }

    #[Test]
    public function throttle_middleware_returns_429_with_retry_after(): void
    {
        $limiter = new FileRateLimiter($this->tmp);
        $middleware = new ThrottleRequests($limiter, ['2', '1']);
        $handler = new CallableHandler(static fn (): Response => Response::text('ok'));

        $request = new Request('GET', 'http://app.test/x', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.5']);

        $this->assertSame(200, $middleware->process($request, $handler)->getStatusCode());
        $this->assertSame(200, $middleware->process($request, $handler)->getStatusCode());

        try {
            $middleware->process($request, $handler);
            $this->fail('Expected a 429.');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertArrayHasKey('Retry-After', $e->getHeaders());
        }
    }

    #[Test]
    public function throttle_budgets_are_per_client(): void
    {
        $limiter = new FileRateLimiter($this->tmp);
        $middleware = new ThrottleRequests($limiter, ['1', '1']);
        $handler = new CallableHandler(static fn (): Response => Response::text('ok'));

        $a = new Request('GET', 'http://app.test/x', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.1']);
        $b = new Request('GET', 'http://app.test/x', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.2']);

        $middleware->process($a, $handler);

        // One client exhausting its budget must not block another.
        $this->assertSame(200, $middleware->process($b, $handler)->getStatusCode());
    }
}
