<?php

use App\Http\Controllers\Api\CapturePostingController;
use App\Http\Middleware\LimitRequestBody;
use Illuminate\Support\Facades\Route;

// The one contract the extension and backend meet at. The OpenAPI document is
// generated from these routes, so a change here is a change to both sides.
// Authentication runs first: neither middleware reads the request body, so
// nothing is gained by refusing an oversized body to a caller with no token,
// and the sizes in that warning are a line anyone can ask to have written. The
// refusal itself is logged once, because a token this instance does not hold
// is the one thing about a capture the owner cannot see from anywhere else.
Route::prefix('v1')
    ->middleware(['auth:sanctum', LimitRequestBody::class])
    ->group(function (): void {
        Route::post('postings', CapturePostingController::class)->name(CapturePostingController::ROUTE);
    });
