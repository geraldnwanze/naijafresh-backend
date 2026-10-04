<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ])),

    'allowed_origins_patterns' => [
        // Vercel preview deployments, e.g. https://naijafresh-frontend-git-*.vercel.app
        '#^https://.*\.vercel\.app$#',
        // ngrok tunnels used for demos.
        '#^https://.*\.ngrok(-free)?\.app$#',
        '#^https://.*\.ngrok\.io$#',
        // Local network testing (phones on the same wifi).
        '#^http://(192\.168|10)\.[0-9.]+(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
