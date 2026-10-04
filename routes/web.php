<?php

use Illuminate\Support\Facades\Route;

// API-only app: the storefront is a separate site, so the root just says hello.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'status' => 'ok',
]));
