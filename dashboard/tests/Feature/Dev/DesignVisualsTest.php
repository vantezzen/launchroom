<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot open the design showcase', function (): void {
    $this->get(route('dev.design.visuals'))->assertRedirect(route('login'));
});

test('the design showcase is not available outside the local environment', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('dev.design.visuals'))
        ->assertNotFound();
});

test('the design showcase renders locally for signed-in users', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $this->actingAs(User::factory()->create())
        ->get(route('dev.design.visuals'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('dev/visuals', shouldExist: false));
});
