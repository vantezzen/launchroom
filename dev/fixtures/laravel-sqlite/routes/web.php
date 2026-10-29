<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => 'laravel-sqlite from launchroom');

// Which database Laravel uses and which PDO drivers PHP has (docs/04 §3.1).
Route::get('/driver', fn () => response()->json([
    'connection' => config('database.default'),
    'drivers' => PDO::getAvailableDrivers(),
]));
