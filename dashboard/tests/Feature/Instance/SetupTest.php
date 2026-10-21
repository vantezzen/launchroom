<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

function ownerPayload(string $token): array
{
    return [
        'token' => $token,
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ];
}

test('the setup token command prints a token and stores only its hash', function (): void {
    $this->artisan('launchroom:setup-token')->expectsOutputToContain('/setup?token=lr_setup_')->assertSuccessful();

    expect(InstanceSettings::current()->setup_token_hash)->toHaveLength(64)
        ->and(InstanceSettings::current()->toArray())->not->toHaveKey('setup_token_hash');
});

test('setup creates the instance owner and their personal team', function (): void {
    Artisan::call('launchroom:setup-token', ['--plain' => true]);
    $token = trim(Artisan::output());

    $this->get(route('setup.show'))->assertOk();
    $this->post(route('setup.store'), ownerPayload($token))->assertRedirect(route('dashboard'));

    $owner = User::query()->sole();
    $team = $owner->currentTeam()->sole();

    expect($owner->is_instance_owner)->toBeTrue()
        ->and($owner->email_verified_at)->not->toBeNull()
        ->and($team->personal)->toBeTrue()
        ->and($owner->roleIn($team))->toBe(TeamRole::Owner)
        ->and(InstanceSettings::current()->setup_completed_at)->not->toBeNull()
        ->and(InstanceSettings::current()->setup_token_hash)->toBeNull();
    $this->assertAuthenticatedAs($owner);
});

test('setup shows the connected server only to the holder of a valid token', function (): void {
    Artisan::call('launchroom:setup-token', ['--plain' => true]);
    $token = trim(Artisan::output());
    // The installer enrolls the control-plane server before the owner exists.
    $server = Server::factory()->online()->create(['team_id' => Team::factory()->create()->id, 'is_control_plane' => true, 'name' => 'fra-1']);

    $this->get(route('setup.show', ['token' => $token]))->assertOk()
        ->assertInertia(fn ($page) => $page->component('setup')
            ->where('token', $token)
            ->where('server.id', $server->public_id)
            ->where('server.name', 'fra-1')
            ->where('server.status', 'online')
            ->has('server.device.kind')
            ->where('instanceUrl', rtrim((string) config('app.url'), '/')));

    foreach ([null, 'lr_setup_wrong'] as $wrong) {
        $this->get(route('setup.show', $wrong === null ? [] : ['token' => $wrong]))
            ->assertInertia(fn ($page) => $page->where('server', null));
    }

    $this->travel(61)->minutes();
    $this->get(route('setup.show', ['token' => $token]))->assertInertia(fn ($page) => $page->where('server', null));
});

test('setup requires the right, unexpired token', function (): void {
    Artisan::call('launchroom:setup-token', ['--plain' => true]);
    $token = trim(Artisan::output());

    $this->post(route('setup.store'), ownerPayload('lr_setup_wrong'))->assertSessionHasErrors('token');

    $this->travel(61)->minutes();
    $this->post(route('setup.store'), ownerPayload($token))->assertSessionHasErrors('token');

    expect(User::query()->count())->toBe(0);
});

test('setup is gone once a user exists', function (): void {
    User::factory()->create();

    $this->get(route('setup.show'))->assertNotFound();
    $this->post(route('setup.store'), ownerPayload('anything'))->assertNotFound();
    $this->artisan('launchroom:setup-token')->assertFailed();
});

test('two-factor is recommended for instance owners, never required (D33)', function (): void {
    $owner = memberOf(Team::factory()->create(), TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true, 'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    $owner->refresh();

    $this->actingAs($owner)->get(route('servers.index'))->assertOk();
    $this->actingAs($owner)->get(route('git.edit'))->assertOk();
    $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('onboarding.show'));

    // The recommendation shows until two-factor is on or it is dismissed.
    $this->actingAs($owner)->get(route('onboarding.show'))->assertInertia(fn ($page) => $page
        ->where('onboarding.recommendTwoFactor', true));

    $owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

    $this->actingAs($owner)->get(route('onboarding.show'))->assertInertia(fn ($page) => $page
        ->where('onboarding.recommendTwoFactor', false));
});

test('other users are not forced into 2FA', function (): void {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertRedirect(route('projects.index'));
});

test('public registration is disabled', function (): void {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'x', 'email' => 'x@example.com', 'password' => 'password', 'password_confirmation' => 'password'])->assertNotFound();
});
