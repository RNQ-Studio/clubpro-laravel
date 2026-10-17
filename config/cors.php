<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    /*
    | Paths that CORS headers should be applied to.
    | Default includes 'api/*' to cover all mobile & web API endpoints.
    */
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'oauth/*'],

    /*
    | Allowed HTTP methods for CORS requests.
    | Set to '*' to allow all standard methods (GET, POST, PUT, DELETE, PATCH, OPTIONS).
    */
    'allowed_methods' => ['*'],

    /*
    | Allowed origin strings.
    | For local testing or general APIs, '*' is acceptable.
    | For production, specify concrete web origins, e.g., ['https://anaklangit.manahpro.web.id'].
    */
    'allowed_origins' => ['*'],

    /*
    | Regular expression patterns matching allowed origins.
    | Useful for wildcards in subdomains, e.g. ['https://*.manahpro.web.id']
    */
    'allowed_origins_patterns' => [],

    /*
    | Headers that are allowed in cross-origin requests.
    | Set to '*' to allow all client-sent headers (including Authorization, Content-Type, Accept).
    */
    'allowed_headers' => ['*'],

    /*
    | Headers that should be exposed to the user agent/client.
    */
    'exposed_headers' => [],

    /*
    | How long the results of a preflight request can be cached (in seconds).
    | 0 means no caching, or set a larger value like 86400 (24 hours) to reduce options requests.
    */
    'max_age' => 0,

    /*
    | Indicates whether the request can be made using credentials (cookies, authorization headers, TLS client certificates).
    | Turn this on (true) if you rely on cookies/Sanctum for session-based cross-origin web clients.
    */
    'supports_credentials' => false,

];
