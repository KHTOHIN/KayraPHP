<?php

declare(strict_types=1);

namespace Kayra\Http\Middleware;

use Kayra\Config\Repository;
use Kayra\Http\Uri;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applies X-Forwarded-* headers, but only from proxies you have declared.
 *
 * Forwarded headers are trivially forged by any client. Honouring them
 * unconditionally lets an attacker spoof their IP address (defeating rate
 * limits and audit logs) and the request scheme (defeating https-only checks).
 * This middleware therefore does nothing until config/security.php lists the
 * proxy addresses that are actually in front of the application.
 */
final class TrustProxies implements MiddlewareInterface
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var list<string> $proxies */
        $proxies = $this->config->array('security.trusted_proxies', []);

        if ($proxies === [] || !$this->fromTrustedProxy($request, $proxies)) {
            return $handler->handle($request);
        }

        $request = $this->applyForwardedProto($request);
        $request = $this->applyForwardedHost($request);
        $request = $this->applyForwardedFor($request);

        return $handler->handle($request);
    }

    /**
     * @param list<string> $proxies
     */
    private function fromTrustedProxy(ServerRequestInterface $request, array $proxies): bool
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        if (!is_string($remote)) {
            return false;
        }

        foreach ($proxies as $proxy) {
            if ($proxy === '*' || $proxy === $remote || $this->inCidr($remote, $proxy)) {
                return true;
            }
        }

        return false;
    }

    private function inCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $bits = (int) $bits;

        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private function applyForwardedProto(ServerRequestInterface $request): ServerRequestInterface
    {
        $proto = $this->firstValue($request->getHeaderLine('X-Forwarded-Proto'));

        if ($proto !== 'http' && $proto !== 'https') {
            return $request;
        }

        return $request->withUri($request->getUri()->withScheme($proto), preserveHost: true);
    }

    private function applyForwardedHost(ServerRequestInterface $request): ServerRequestInterface
    {
        $host = $this->firstValue($request->getHeaderLine('X-Forwarded-Host'));

        if ($host === '') {
            return $request;
        }

        // Reject anything that is not a bare host[:port].
        if (preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host) !== 1) {
            return $request;
        }

        [$name, $port] = array_pad(explode(':', $host, 2), 2, null);

        $uri = $request->getUri()->withHost($name);

        if ($port !== null) {
            $uri = $uri->withPort((int) $port);
        }

        return $request->withUri($uri);
    }

    private function applyForwardedFor(ServerRequestInterface $request): ServerRequestInterface
    {
        $client = $this->firstValue($request->getHeaderLine('X-Forwarded-For'));

        if ($client === '' || filter_var($client, FILTER_VALIDATE_IP) === false) {
            return $request;
        }

        $server = $request->getServerParams();
        $server['REMOTE_ADDR'] = $client;

        // ServerRequestInterface has no wither for server params, so the client
        // address is exposed as an attribute instead.
        return $request->withAttribute('client_ip', $client);
    }

    /**
     * X-Forwarded-* headers are comma-separated lists; the client is first.
     */
    private function firstValue(string $header): string
    {
        if ($header === '') {
            return '';
        }

        return trim(explode(',', $header, 2)[0]);
    }
}
