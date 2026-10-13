<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\ReviewPanelProvider;
use App\Providers\LlmServiceProvider;
use App\Providers\OpenApiServiceProvider;
use App\Providers\RenderingServiceProvider;

return [
    AppServiceProvider::class,
    LlmServiceProvider::class,
    OpenApiServiceProvider::class,
    RenderingServiceProvider::class,
    ReviewPanelProvider::class,
];
