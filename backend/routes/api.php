<?php

use App\Http\Controllers\Api\CapturePostingController;
use App\Http\Middleware\LimitRequestBody;
use Illuminate\Support\Facades\Route;

// The one contract the extension and backend meet at. The OpenAPI document is
// generated from these routes, so a change here is a change to both sides.
// Authentication runs first: neither middleware reads the request body, so
// nothing is gained by refusing an oversized body to a caller with no token,
// and a log line written on an unauthenticated request is a log line anyone
// can ask for.
Route::prefix('v1')
    ->middleware(['auth:sanctum', LimitRequestBody::class])
    ->group(function (): void {
        Route::post('postings', CapturePostingController::class)->name('api.v1.postings.capture');
    });
