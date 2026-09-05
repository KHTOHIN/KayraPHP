<?php

declare(strict_types=1);

namespace Kayra\Http\Middleware;

use Kayra\Config\Repository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds the response headers that mitigate common browser-side attacks.
 *
 * Every header is overridable through config/security.php; an application that
 * sets a header itself is never overwritten.
 */
final class SecurityHeaders implements MiddlewareInterface
{
    public function __construct(
        private readonly Repository $config,
        private readonly ?\Kayra\View\Factory $views = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // A per-request nonce lets a strict CSP allow the application's own
        // inline scripts without opening the door to injected ones. It is
        // generated before the response so templates can reference it.
        $nonce = base64_encode(random_bytes(16));
        $request = $request->withAttribute('csp_nonce', $nonce);

        $this->views?->shareForRequest('csp_nonce', $nonce);

        $response = $handler->handle($request);

        /** @var array<string, string|null> $headers */
        $headers = $this->config->array('security.headers', $this->defaults());

        foreach ($headers as $name => $value) {
            if ($value === null || $value === '' || $response->hasHeader($name)) {
                continue;
            }

            // {nonce} in the configured policy is replaced with this request's
            // value, so the policy stays declarative in config.
            $response = $response->withHeader($name, str_replace('{nonce}', $nonce, $value));
        }

        // HSTS is only meaningful over TLS, and dangerous to send over plain HTTP.
        $hsts = $this->config->get('security.hsts');

        if (is_string($hsts) && $hsts !== '' && $request->getUri()->getScheme() === 'https') {
            $response = $response->withHeader('Strict-Transport-Security', $hsts);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function defaults(): array
    {
        return [
            // Stop the browser from second-guessing declared content types.
            'X-Content-Type-Options' => 'nosniff',
            // Deny framing unless the application opts in via CSP.
            'X-Frame-Options'        => 'DENY',
            // Do not leak full URLs to third parties.
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',

            // Cross-origin isolation. These are safe defaults: they restrict
            // what other origins may do with this document, not what this
            // document may do.
            'Cross-Origin-Opener-Policy'   => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
    }
}
