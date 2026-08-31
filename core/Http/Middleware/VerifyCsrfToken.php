<?php

declare(strict_types=1);

namespace Kayra\Http\Middleware;

use Kayra\Config\Repository;
use Kayra\Exceptions\HttpException;
use Kayra\Http\Request;
use Kayra\Session\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects state-changing requests that do not carry the session's CSRF token.
 *
 * Only the unsafe methods are checked. GET, HEAD and OPTIONS are defined as
 * safe and must not change state, so requiring a token on them would break
 * ordinary navigation without adding protection.
 *
 * The token is accepted from the `_token` form field or the `X-CSRF-Token`
 * header, and compared in constant time.
 */
final class VerifyCsrfToken implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    public function __construct(private readonly Repository $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->shouldSkip($request)) {
            return $handler->handle($request);
        }

        $session = $request->getAttribute('session');

        if (!$session instanceof Session) {
            // No session means no token to compare against. Failing closed is
            // the only safe option: the alternative is silently unprotected.
            throw new HttpException(
                419,
                'CSRF verification is enabled but no session was started. '
                . 'Add the StartSession middleware before VerifyCsrfToken.',
            );
        }

        if (!$session->verifyToken($this->tokenFrom($request))) {
            // 419 rather than 403: it distinguishes "your token expired, retry"
            // from "you are not allowed to do this".
            throw new HttpException(419, 'CSRF token mismatch.');
        }

        return $handler->handle($request);
    }

    private function shouldSkip(ServerRequestInterface $request): bool
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return true;
        }

        $path = $request->getUri()->getPath();

        foreach ($this->config->array('session.csrf_except', []) as $pattern) {
            if (!is_string($pattern)) {
                continue;
            }

            if ($pattern === $path || fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function tokenFrom(ServerRequestInterface $request): string
    {
        $body = $request->getParsedBody();

        if (is_array($body) && isset($body['_token']) && is_string($body['_token'])) {
            return $body['_token'];
        }

        if ($request instanceof Request) {
            $fromJson = $request->input('_token');

            if (is_string($fromJson) && $fromJson !== '') {
                return $fromJson;
            }
        }

        $header = $request->getHeaderLine('X-CSRF-Token');

        return $header !== '' ? $header : $request->getHeaderLine('X-XSRF-Token');
    }
}
