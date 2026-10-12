<?php

use App\Observability\Status\MasterCvSection;
use App\Observability\Status\ProcessesSection;
use App\Observability\Status\QueueSection;

return [

    'master_cv' => [
        // A read-only bind mount. The application only ever reads it.
        'path' => env('MASTER_CV_PATH', '/var/lib/cv-tailor/master-cv.md'),
    ],

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
