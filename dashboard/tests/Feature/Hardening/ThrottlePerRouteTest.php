<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * `throttle:N,M` limits count per user and route (ThrottleRequestsPerRoute):
 * one busy route never uses up another route's budget.
 */
beforeEach(function (): void {
    Route::middleware(['web', 'auth', 'throttle:2,1'])->group(function (): void {
        Route::post('__throttle/a', fn (): string => 'a');
        Route::post('__throttle/b', fn (): string => 'b');
    });
});

test('each route has its own budget per user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/__throttle/a')->assertOk();
    $this->actingAs($user)->post('/__throttle/a')->assertOk();
    $this->actingAs($user)->post('/__throttle/a')->assertTooManyRequests();

    $this->actingAs($user)->post('/__throttle/b')->assertOk();
    $this->actingAs(User::factory()->create())->post('/__throttle/a')->assertOk();
});
