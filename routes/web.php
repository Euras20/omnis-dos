<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Minimal surface. Business API lives under /api/v1.
| Architecture: 04-folder-structure.md
*/

Route::get('/health/live', fn () => response()->json(['status' => 'ok']));
Route::get('/health/ready', fn () => response()->json(['status' => 'ok']));
