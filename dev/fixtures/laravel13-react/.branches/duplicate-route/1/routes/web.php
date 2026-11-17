<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

// Two routes with one name: `php artisan route:cache` refuses this app.
Route::get('/about', fn () => 'about')->name('about');
Route::get('/about-us', fn () => 'about us')->name('about');
