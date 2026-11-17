<?php

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // FRONTEND_URL may be pasted with a trailing slash; CORS compares it exactly.
    'allowed_origins' => [rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
