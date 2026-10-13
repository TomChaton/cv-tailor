<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\ReviewPanelProvider;
use App\Providers\LlmServiceProvider;
use App\Providers\RenderingServiceProvider;

return [
    AppServiceProvider::class,
    LlmServiceProvider::class,
    RenderingServiceProvider::class,
    ReviewPanelProvider::class,
];
