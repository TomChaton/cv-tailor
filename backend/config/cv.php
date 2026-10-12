<?php

use App\Observability\Status\MasterCvSection;
use App\Observability\Status\ProcessesSection;
use App\Observability\Status\QueueSection;

return [

    'master_cv' => [
        // A read-only bind mount. The application only ever reads it.
        'path' => env('MASTER_CV_PATH', '/var/lib/cv-tailor/master-cv.md'),
    ],

    // The application listens on plain HTTP and knows nothing about how traffic
    // reaches it, so the trusted set is deployment configuration. Empty trusts
    // no proxy; `*` trusts any. Authentication never relies on a proxy: see the
    // system briefing.
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),
    ],

    // `cv:status` renders these in order. A new area appends its own section
    // and leaves the ones above it alone.
    'status_sections' => [
        ProcessesSection::class,
        QueueSection::class,
        MasterCvSection::class,
    ],

];
