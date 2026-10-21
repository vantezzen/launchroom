<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('confirm password screen can be rendered', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('auth/confirm-password'));
});

test('password confirmation requires authentication', function (): void {
    $this->get(route('password.confirm'))->assertRedirect(route('login'));
});
