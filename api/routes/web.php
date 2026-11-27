<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// The API server serves JSON only; the user interfaces live in web/ and mobile/.
Route::get('/', static fn () => response()->json([
    'app' => config('app.name'),
    'api' => url('/api/v1'),
]));
