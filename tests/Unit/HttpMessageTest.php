<?php

declare(strict_types=1);

namespace Kayra\Tests\Unit;

use InvalidArgumentException;
use Kayra\Http\Request;
use Kayra\Http\Response;
use Kayra\Http\Stream;
use Kayra\Http\UploadedFile;
use Kayra\Http\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Uri::class)]
#[CoversClass(Request::class)]
#[CoversClass(Response::class)]
#[CoversClass(Stream::class)]
#[CoversClass(UploadedFile::class)]
final class HttpMessageTest extends TestCase
{
    /* ---------------------------------------------------------------- URI */

    #[Test]
    public function uri_normalises_scheme_and_host_but_preserves_userinfo_case(): void
    {
        $uri = new Uri('HTTPS://Bob:pw@EXAMPLE.com:8443/a?x=1#f');

        $this->assertSame('https', $uri->getScheme());
        $this->assertSame('example.com', $uri->getHost());
        // RFC 3986: scheme and host are case-insensitive, userinfo is not.
        $this->assertSame('Bob:pw', $uri->getUserInfo());
        $this->assertSame(8443, $uri->getPort());
    }

    #[Test]
    #[DataProvider('defaultPorts')]
    public function uri_omits_the_default_port_for_its_scheme(string $input): void
    {
        $this->assertNull((new Uri($input))->getPort());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function defaultPorts(): iterable
    {
        yield 'http'  => ['http://a.test:80/x'];
        yield 'https' => ['https://a.test:443/x'];
    }

    #[Test]
    public function uri_round_trips(): void
    {
        $this->assertSame('https://a.test/p?q=1#f', (string) new Uri('https://a.test/p?q=1#f'));
    }

    #[Test]
    public function uri_encodes_unsafe_path_characters_without_double_encoding(): void
    {
        $this->assertSame('/a%20b', (new Uri('/a b'))->getPath());
        $this->assertSame('/a%20b', (new Uri('/a%20b'))->getPath());
    }

    #[Test]
    public function uri_rejects_an_out_of_range_port(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $discarded = (new Uri('/x'))->withPort(70_000);
    }

    /* ------------------------------------------------------------ headers */

    #[Test]
    public function header_lookup_is_case_insensitive_but_casing_is_preserved(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/html']);

        $this->assertSame('text/html', $response->getHeaderLine('CONTENT-TYPE'));
        $this->assertArrayHasKey('Content-Type', $response->getHeaders());
    }

    #[Test]
    public function replacing_a_header_does_not_leave_the_old_casing_behind(): void
    {
        $response = (new Response(200, ['Content-Type' => 'text/html']))
            ->withHeader('content-type', 'application/json');

        $this->assertCount(1, $response->getHeaders());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function header_values_containing_crlf_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $discarded = (new Response())->withHeader('X-Test', "value\r\nInjected: yes");
    }

    #[Test]
    public function invalid_header_names_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $discarded = (new Response())->withHeader('Bad Name', 'v');
    }

    /* ----------------------------------------------------------- response */

    #[Test]
    public function withers_do_not_mutate_the_receiver(): void
    {
        $response = Response::html('body');

        $discarded = $response->withStatus(404);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function json_leaves_slashes_and_unicode_unescaped(): void
    {
        $this->assertSame('{"u":"a/b"}', (string) Response::json(['u' => 'a/b'])->getBody());
        $this->assertSame('{"t":"বাংলা"}', (string) Response::json(['t' => 'বাংলা'])->getBody());
    }

    #[Test]
    public function redirect_rejects_a_location_containing_crlf(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Response::redirect("/x\r\nSet-Cookie: a=b");
    }

    #[Test]
    public function cookies_default_to_secure_httponly_and_samesite(): void
    {
        $cookie = Response::html('x')->withCookie('sid', 'abc')->getCookies()[0];

        $this->assertStringContainsString('Secure', $cookie);
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('SameSite=Lax', $cookie);
    }

    /* ------------------------------------------------------------ request */

    #[Test]
    public function request_derives_the_host_header_from_the_uri(): void
    {
        $this->assertSame('api.test', (new Request('GET', 'https://api.test/x'))->getHeaderLine('Host'));
    }

    #[Test]
    public function json_body_is_decoded_memoised_and_leaves_the_stream_readable(): void
    {
        $request = new Request(
            'POST',
            'https://api.test/x',
            ['Content-Type' => 'application/json'],
            Stream::of('{"name":"kayra"}'),
        );

        $this->assertSame(['name' => 'kayra'], $request->json());
        $this->assertSame(['name' => 'kayra'], $request->json());
        $this->assertSame('{"name":"kayra"}', (string) $request->getBody());
    }

    #[Test]
    public function malformed_json_decodes_to_null_rather_than_throwing(): void
    {
        $request = new Request(
            'POST',
            '/x',
            ['Content-Type' => 'application/json'],
            Stream::of('{not json'),
        );

        $this->assertNull($request->json());
    }

    #[Test]
    public function input_prefers_the_body_then_falls_back_to_the_query_string(): void
    {
        $request = (new Request('POST', '/x', ['Content-Type' => 'application/json'], Stream::of('{"a":"body"}')))
            ->withQueryParams(['a' => 'query', 'b' => 'query-only']);

        $this->assertSame('body', $request->input('a'));
        $this->assertSame('query-only', $request->input('b'));
    }

    #[Test]
    public function bearer_token_is_read_from_the_authorization_header(): void
    {
        $request = new Request('GET', '/x', ['Authorization' => 'Bearer abc123']);

        $this->assertSame('abc123', $request->bearerToken());
        $this->assertNull((new Request('GET', '/x'))->bearerToken());
    }

    #[Test]
    public function with_uri_replaces_the_host_unless_preserve_host_is_set(): void
    {
        $request = new Request('GET', 'https://api.test/x');

        $this->assertSame('other.test', $request->withUri(new Uri('https://other.test/y'))->getHeaderLine('Host'));
        $this->assertSame('api.test', $request->withUri(new Uri('https://other.test/y'), true)->getHeaderLine('Host'));
    }

    /* ------------------------------------------------------- uploaded file */

    #[Test]
    public function it_transposes_phps_multi_upload_array_layout(): void
    {
        $files = UploadedFile::normalize([
            'docs' => [
                'tmp_name' => ['/tmp/1', '/tmp/2'],
                'size'     => [1, 2],
                'error'    => [0, 0],
                'name'     => ['x.pdf', 'y.pdf'],
                'type'     => ['application/pdf', 'application/pdf'],
            ],
        ]);

        $this->assertCount(2, $files['docs']);
        $this->assertSame('y.pdf', $files['docs'][1]->getClientFilename());
    }

    #[Test]
    #[DataProvider('hostileFilenames')]
    public function safe_filename_strips_path_traversal(string $client, string $expected): void
    {
        $this->assertSame($expected, (new UploadedFile('/tmp/x', 1, 0, $client))->safeFilename());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function hostileFilenames(): iterable
    {
        yield 'unix traversal'    => ['../../../etc/passwd', 'passwd'];
        yield 'windows traversal' => ['C:\\windows\\system32\\evil.exe', 'evil.exe'];
        yield 'leading dots'      => ['...hidden', 'hidden'];
        yield 'empty'             => ['', 'upload'];
    }
}
