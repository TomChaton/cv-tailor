<?php

use Illuminate\Support\Facades\Route;

// The review UI is the only thing served to a browser.
Route::redirect('/', '/review');
