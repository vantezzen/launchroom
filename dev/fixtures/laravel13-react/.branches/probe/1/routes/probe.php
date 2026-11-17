<?php

// Diagnostic only (probe branch): what the app believes about its URL, the
// request's scheme and the visitor's address.

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/probe', fn (Request $request) => response()->json([
    'appUrl' => config('app.url'),
    'url' => url('/'),
    'route' => route('home'),
    'storageUrl' => Storage::disk('public')->url('probe.txt'),
    'secure' => $request->secure(),
    'ip' => $request->ip(),
    'environment' => app()->environment(),
    'database' => config('database.default'),
    'routesCached' => app()->routesAreCached(),
    'configCached' => app()->configurationIsCached(),
]));
