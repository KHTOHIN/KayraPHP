<?php

declare(strict_types=1);

return [
    /*
    |----------------------------------------------------------------------
    | Response security headers
    |----------------------------------------------------------------------
    | Set a value to null to omit that header.
    */

    'headers' => [
        'X-Content-Type-Options'            => 'nosniff',
        'X-Frame-Options'                   => 'DENY',
        'Referrer-Policy'                   => 'strict-origin-when-cross-origin',
        'X-Permitted-Cross-Domain-Policies' => 'none',

        // Cross-origin isolation: restricts what other origins may do with
        // this document, not what this document may do.
        'Cross-Origin-Opener-Policy'   => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        // Enable only if every embedded resource sends CORP/CORS; it breaks
        // third-party embeds that do not.
        'Cross-Origin-Embedder-Policy' => null,

        // Start strict and relax per application; a permissive default CSP is
        // worse than none because it looks like protection.
        //
        // {nonce} is replaced per request, and the same value is shared with
        // templates as $csp_nonce — so the application's own inline scripts can
        // be allowed without resorting to 'unsafe-inline':
        //
        //   "default-src 'self'; script-src 'self' 'nonce-{nonce}'; object-src 'none'"
        'Content-Security-Policy'           => env('CSP_HEADER'),
        'Permissions-Policy'                => null,
    ],

    /*
    |----------------------------------------------------------------------
    | HTTP Strict Transport Security
    |----------------------------------------------------------------------
    | Only ever sent over https. Enable this once TLS is confirmed working:
    | a browser that has seen it will refuse plain http for max-age seconds.
    |
    | Suggested: 'max-age=31536000; includeSubDomains'
    */

    'hsts' => env('HSTS_HEADER'),

    /*
    |----------------------------------------------------------------------
    | Trusted proxies
    |----------------------------------------------------------------------
    | X-Forwarded-* headers are ignored unless the request arrives from one of
    | these addresses. Leave empty unless a proxy really is in front of the
    | application: trusting these headers blindly lets any client spoof its IP
    | address and the request scheme.
    |
    | Accepts exact addresses and CIDR ranges, e.g. ['10.0.0.0/8'].
    | '*' trusts every source and should only be used where the network layer
    | already guarantees that nothing else can reach the application.
    */

    'trusted_proxies' => array_filter(explode(',', (string) env('TRUSTED_PROXIES', ''))),

    /*
    |----------------------------------------------------------------------
    | Trusted hosts
    |----------------------------------------------------------------------
    | Requests whose Host header is not listed here are rejected with a 400.
    |
    | The Host header is attacker-controlled, and any absolute URL the
    | application builds from the request (a password-reset link, a redirect)
    | inherits it. Leave empty in development; set it in production.
    |
    | Accepts exact hostnames and '*.example.com' wildcards.
    */

    'trusted_hosts' => array_filter(explode(',', (string) env('TRUSTED_HOSTS', ''))),
];
