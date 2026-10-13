<?php

use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;

return [

    // Every route under `/api/v*` is part of the contract. The wildcard is
    // deliberate: it keeps the document's paths the paths the extension calls
    // (`/api/v1/postings`), where a static `api` would strip the prefix into
    // the server URL and leave the client guessing where the version went.
    'api_path' => 'api/v*',

    // Written by `php artisan scramble:export`, relative to the backend root.
    'export_path' => 'openapi.json',

    'info' => [
        // The document's own version. The API's version is in its paths.
        'version' => '1.0.0',
    ],

    'ui' => [
        // Pinned rather than read from `app.name`: the document is committed,
        // and a contract that changes because somebody renamed their instance
        // is not a contract.
        'title' => 'cv-tailor',
    ],

    // The extension is given a base URL and a token, so the document names no
    // server: see OpenApiServiceProvider.
    'servers' => null,

    // Bearer auth is read off the route's `auth:sanctum` middleware, so the
    // security scheme follows the code rather than being asserted beside it.
    'security_strategy' => MiddlewareAuthSecurityStrategy::class,

];
