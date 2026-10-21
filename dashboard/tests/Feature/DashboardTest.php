<?php

declare(strict_types=1);

use App\Models\InstanceSettings;
use App\Models\User;

test('guests are redirected to the login page', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('the dashboard sends members to their projects', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertRedirect(route('projects.index'));
});

test('instance owners land on the first-run checklist until it is complete or dismissed', function (): void {
    $owner = User::factory()->withTwoFactor()->create();
    $owner->forceFill(['is_instance_owner' => true])->save();

    $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('onboarding.show'));

    InstanceSettings::current()->forceFill(['onboarding' => ['dismissedAt' => now()->toIso8601ZuluString()]])->save();

    $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('projects.index'));
});
