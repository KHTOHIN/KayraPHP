<?php

declare(strict_types=1);

namespace Kayra\Http\Middleware;

use Kayra\Exceptions\HttpException;
use Kayra\RateLimiter\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fixed-window rate limiting.
 *
 * Registered as `throttle:60,1` — 60 requests per 1 minute. The parameters
 * arrive through {@see \Kayra\Http\MiddlewareParameters}.
 *
 * Requests are keyed by authenticated user when there is one and by client IP
 * otherwise, so a shared NAT does not let one user exhaust everyone's budget
 * once they have signed in.
 */
final class ThrottleRequests implements MiddlewareInterface
{
    private readonly int $maxAttempts;

    private readonly int $decaySeconds;

    /**
     * @param list<string> $parameters [maxAttempts, decayMinutes]
     */
    public function __construct(
        private readonly RateLimiter $limiter,
        array $parameters = [],
    ) {
        $this->maxAttempts = max(1, (int) ($parameters[0] ?? 60));
        $this->decaySeconds = max(1, (int) ($parameters[1] ?? 1) * 60);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $this->key($request);

        if ($this->limiter->tooManyAttempts($key, $this->maxAttempts)) {
            $retryAfter = $this->limiter->availableIn($key);

            throw new HttpException(429, 'Too many requests.', [
                'Retry-After'           => (string) $retryAfter,
                'X-RateLimit-Limit'     => (string) $this->maxAttempts,
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset'     => (string) (time() + $retryAfter),
            ]);
        }

        $used = $this->limiter->hit($key, $this->decaySeconds);

        $response = $handler->handle($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $this->maxAttempts - $used));
    }

    private function key(ServerRequestInterface $request): string
    {
        $user = $request->getAttribute('auth.id');

        if (is_string($user) || is_int($user)) {
            return 'user:' . $user;
        }

        // TrustProxies sets client_ip only when the request came through a
        // proxy that is actually trusted; otherwise the SAPI value is used.
        $ip = $request->getAttribute('client_ip')
            ?? ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');

        return 'ip:' . (is_string($ip) ? $ip : 'unknown') . '|' . $request->getUri()->getPath();
    }
}
