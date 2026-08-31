<?php

declare(strict_types=1);

namespace Kayra\Tests\Unit;

use Kayra\Config\Repository;
use Kayra\Env\Env;
use Kayra\Foundation\Optimizer;
use Kayra\Http\Middleware\ValidateHost;
use Kayra\Http\Request;
use Kayra\Http\Response;
use Kayra\Http\Stream;
use Kayra\Pipeline\CallableHandler;
use Kayra\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regressions for bugs found by auditing the framework against itself.
 *
 * Each test here failed before its fix. They are grouped deliberately: this file
 * is the record of what went wrong, so the same mistake cannot return quietly.
 */
final class RegressionTest extends TestCase
{
    /* ------------------------------------------------------------------ A1 */

    #[Test]
    public function replacing_the_body_invalidates_the_decoded_json_memo(): void
    {
        // Before the fix, the clone kept the parent's decoded payload, so
        // middleware that rewrote the body was read back as the original.
        $request = new Request(
            'POST',
            '/x',
            ['Content-Type' => 'application/json'],
            Stream::of('{"v":1}'),
        );

        $this->assertSame(['v' => 1], $request->json());

        $rewritten = $request->withBody(Stream::of('{"v":2}'));

        $this->assertSame(['v' => 2], $rewritten->json(), 'Stale JSON memo carried into the clone.');
        $this->assertSame(['v' => 1], $request->json(), 'The original must be unchanged.');
    }

    /* ------------------------------------------------------------------ B1 */

    #[Test]
    public function a_route_cache_built_for_a_different_route_count_is_rejected(): void
    {
        // The dispatch table addresses routes by position. Before the fix, a
        // route file that gained a route made every position shift, and requests
        // were silently dispatched to the wrong handler.
        [$file, $dispatch] = $this->buildRouteCache(
            'Route::get("/alpha","ALPHA"); Route::get("/beta","BETA");',
        );

        // Redeploy with an extra route at the front, cache not cleared.
        file_put_contents(
            $file,
            '<?php use Kayra\Routing\Route; Route::get("/gamma","GAMMA"); Route::get("/alpha","ALPHA"); Route::get("/beta","BETA");',
        );

        $router = new Router();
        $router->load([$file]);

        $accepted = $router->useCompiled($dispatch, expectedRoutes: 2);

        $this->assertFalse($accepted, 'A cache built for 2 routes must not be used for 3.');
        $this->assertSame(
            'ALPHA',
            $router->resolve(new Request('GET', 'http://x/alpha'))->route->handler,
            'After rejecting the cache, routing must be correct.',
        );

        unlink($file);
    }

    #[Test]
    public function the_route_fingerprint_changes_when_a_route_file_changes(): void
    {
        [$file] = $this->buildRouteCache('Route::get("/a","A");');

        $before = Optimizer::routeFingerprint([$file]);

        file_put_contents($file, '<?php use Kayra\Routing\Route; Route::get("/a","A"); Route::get("/b","B");');
        clearstatcache();

        $this->assertNotSame($before, Optimizer::routeFingerprint([$file]));

        unlink($file);
    }

    #[Test]
    public function a_matching_route_cache_is_accepted(): void
    {
        [$file, $dispatch] = $this->buildRouteCache('Route::get("/a","A"); Route::get("/b","B");');

        $router = new Router();
        $router->load([$file]);

        $this->assertTrue($router->useCompiled($dispatch, expectedRoutes: 2));
        $this->assertSame('A', $router->resolve(new Request('GET', 'http://x/a'))->route->handler);

        unlink($file);
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildRouteCache(string $body): array
    {
        $file = sys_get_temp_dir() . '/kayra_reg_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, '<?php use Kayra\Routing\Route; ' . $body);

        $router = new Router();
        $router->load([$file]);

        return [$file, $router->compile()];
    }

    /* ------------------------------------------------------------------ C1 */

    #[Test]
    public function request_data_in_the_server_superglobal_does_not_become_configuration(): void
    {
        // Under a web SAPI, $_SERVER mixes process environment with request
        // metadata. Importing it wholesale turned attacker-controlled headers
        // into environment values.
        $original = $_SERVER;

        Env::flush();
        $_SERVER['HTTP_X_INJECTED'] = 'attacker';
        $_SERVER['REQUEST_URI'] = '/attacker/path';
        $_SERVER['KAYRA_REAL_SETTING'] = 'legitimate';

        Env::loadFromProcess();

        $this->assertFalse(Env::has('HTTP_X_INJECTED'), 'Request headers must not become env vars.');
        $this->assertFalse(Env::has('REQUEST_URI'), 'Request metadata must not become env vars.');
        $this->assertSame('legitimate', Env::get('KAYRA_REAL_SETTING'), 'Genuine SAPI variables must survive.');

        $_SERVER = $original;
        Env::flush();
    }

    /* ------------------------------------------------------------------ D1 */

    #[Test]
    #[DataProvider('hostCases')]
    public function the_host_header_is_checked_against_the_allow_list(
        string $host,
        array $allowed,
        int $expectedStatus,
    ): void {
        $middleware = new ValidateHost(new Repository(['security' => ['trusted_hosts' => $allowed]]));

        $response = $middleware->process(
            new Request('GET', 'http://' . $host . '/'),
            new CallableHandler(static fn (): Response => Response::text('reached', 200)),
        );

        $this->assertSame($expectedStatus, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{0: string,1: list<string>, 2: int}>
     */
    public static function hostCases(): iterable
    {
        yield 'no allow-list means no check'   => ['evil.test', [], 200];
        yield 'exact match passes'             => ['app.test', ['app.test'], 200];
        yield 'spoofed host rejected'          => ['evil.test', ['app.test'], 400];
        yield 'wildcard subdomain passes'      => ['api.app.test', ['*.app.test'], 200];
        yield 'wildcard deep subdomain passes' => ['a.b.app.test', ['*.app.test'], 200];
        yield 'wildcard rejects bare domain'   => ['app.test', ['*.app.test'], 400];
        // The important one: a suffix match must not be mistaken for a subdomain.
        yield 'wildcard rejects suffix trick'  => ['evilapp.test', ['*.app.test'], 400];
        yield 'wildcard rejects other domain'  => ['app.test.evil.com', ['*.app.test'], 400];
    }

    /* ------------------------------------------------------------------ E1 */

    #[Test]
    public function an_unparseable_request_target_is_a_client_error_not_a_crash(): void
    {
        // Both the strict and lenient parsers reject this. It must surface as a
        // catchable exception, never as a fatal in the runtime.
        $this->expectException(\InvalidArgumentException::class);

        new Request('GET', '//a:b:c');
    }

    #[Test]
    #[DataProvider('realWorldTargets')]
    public function request_targets_that_strict_rfc_3986_rejects_still_route(string $target): void
    {
        // ext/uri refuses these; real clients send them anyway. The lenient
        // fallback is what keeps them reaching the router as honest 404s.
        $request = new Request('GET', 'http://app.test' . $target);

        $this->assertNotSame('', $request->getUri()->getPath());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function realWorldTargets(): iterable
    {
        yield 'raw space'      => ['/a b'];
        yield 'utf-8 path'     => ['/search/বাংলা'];
        yield 'braces'         => ['/tpl/{id}'];
        yield 'pipe'           => ['/a|b'];
        yield 'caret'          => ['/a^b'];
        yield 'bad percent'    => ['/a%zz'];
    }
}
