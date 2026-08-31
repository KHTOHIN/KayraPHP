<?php

declare(strict_types=1);

namespace Kayra\Http\Middleware;

use Kayra\Config\Repository;
use Kayra\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects requests whose Host header is not on the allow-list.
 *
 * The Host header is attacker-controlled. Any code that builds a URL from the
 * incoming request — a password-reset link, an absolute redirect, a canonical
 * tag — will happily build it against whatever host the attacker supplied.
 * That is the classic host-header poisoning vector: the victim receives a real,
 * signed reset link pointing at the attacker's domain.
 *
 * Configure `security.trusted_hosts` in production. An empty list disables the
 * check, which is correct for local development and reported by `kayra doctor`.
 */
final class ValidateHost implements MiddlewareInterface
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var list<string> $allowed */
        $allowed = $this->config->array('security.trusted_hosts', []);

        if ($allowed === []) {
            return $handler->handle($request);
        }

        $host = $request->getUri()->getHost();

        if ($host === '' || !$this->matches($host, $allowed)) {
            // 400, not 404: the request is malformed, and saying so does not
            // reveal which hosts are valid.
            return Response::text('Invalid Host header.', 400);
        }

        return $handler->handle($request);
    }

    /**
     * @param list<string> $allowed Hostnames, or `*.example.com` wildcards.
     */
    private function matches(string $host, array $allowed): bool
    {
        foreach ($allowed as $pattern) {
            $pattern = strtolower(trim((string) $pattern));

            if ($pattern === '') {
                continue;
            }

            if ($pattern === $host) {
                return true;
            }

            // A leading '*.' matches any single-or-multi-level subdomain, but
            // never the bare domain, and never a suffix match such as
            // "notexample.com" against "*.example.com".
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);

                if (str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
