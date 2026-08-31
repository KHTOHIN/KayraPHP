<?php

declare(strict_types=1);

namespace Kayra\Http\Middleware;

use Kayra\Exceptions\HttpException;
use Kayra\Routing\UrlGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects requests whose URL signature is missing, wrong, or expired.
 *
 * Pair with {@see UrlGenerator::signedRoute()} for links that must be
 * trustworthy without a session — password reset, email verification.
 */
final class ValidateSignature implements MiddlewareInterface
{
    public function __construct(private readonly UrlGenerator $urls)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->urls->hasValidSignature((string) $request->getUri())) {
            // 403 with a deliberately vague message: distinguishing "expired"
            // from "forged" tells an attacker whether the link ever existed.
            throw new HttpException(403, 'Invalid or expired signature.');
        }

        return $handler->handle($request);
    }
}
