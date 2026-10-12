<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\ReviewPanelProvider;
use App\Providers\LlmServiceProvider;

return [
    AppServiceProvider::class,
    LlmServiceProvider::class,
    ReviewPanelProvider::class,
];
