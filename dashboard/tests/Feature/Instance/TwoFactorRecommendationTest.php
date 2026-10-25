<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;

/*
| D33: two-factor is recommended for instance owners, never required.
*/

/** An instance owner (team owner) who never set up two-factor. */
function tfOwner(): User
{
    $owner = memberOf(Team::factory()->create(), TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true, 'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

    return $owner->refresh();
}

test('an owner without two-factor uses every page, including sensitive settings', function (): void {
    $owner = tfOwner();

    foreach (['servers.index', 'projects.index', 'git.edit', 'team.edit', 'updates.edit', 'onboarding.show', 'security.edit'] as $route) {
        $this->actingAs($owner)->get(route($route))->assertOk();
    }
});

test('"Not now" hides the recommendation for that owner only', function (): void {
    $owner = tfOwner();
    $other = tfOwner();

    $this->actingAs($owner)->post(route('onboarding.two-factor.dismiss'))->assertRedirect();

    $this->actingAs($owner)->get(route('onboarding.show'))
        ->assertInertia(fn ($page) => $page->where('onboarding.recommendTwoFactor', false));
    $this->actingAs($other)->get(route('onboarding.show'))
        ->assertInertia(fn ($page) => $page->where('onboarding.recommendTwoFactor', true));

    // Dismissing twice keeps one entry.
    $this->actingAs($owner)->post(route('onboarding.two-factor.dismiss'));
    expect(InstanceSettings::current()->onboarding['twoFactorDismissed'])->toBe([$owner->id]);
});

test('only instance owners can dismiss it', function (): void {
    $this->actingAs(User::factory()->create())->post(route('onboarding.two-factor.dismiss'))->assertForbidden();
});
