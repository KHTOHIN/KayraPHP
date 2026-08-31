<?php

declare(strict_types=1);

return [
    /*
    |----------------------------------------------------------------------
    | Allowed origins
    |----------------------------------------------------------------------
    | Exact origins, including scheme and port. '*' is permitted only while
    | supports_credentials is false — browsers reject the wildcard on
    | credentialed requests, and echoing back an arbitrary origin while
    | allowing credentials would expose authenticated data to any site.
    */

    'allowed_origins' => array_filter(explode(',', (string) env('CORS_ORIGINS', ''))),

    // Regular expressions, e.g. ['#^https://.*\.example\.com$#']
    'allowed_origin_patterns' => [],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],

    // Response headers a browser is allowed to read.
    'exposed_headers' => [],

    // Seconds a preflight result may be cached.
    'max_age' => 86400,

    'supports_credentials' => env('CORS_CREDENTIALS', false),
];
