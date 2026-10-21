<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeDaemon;

/*
| Fresh install order (docs/11 §2): the installer enrolls the control-plane
| host's daemon BEFORE the owner exists (its Caddy serves the dashboard), into
| an unclaimed instance team that the owner claims at /setup.
*/

function bootstrapBeforeSetup(array $options = []): array
{
    Artisan::call('launchroom:servers:bootstrap-local', $options);

    return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
}

test('bootstrap-local works before setup and reuses the unclaimed instance team', function (): void {
    $first = bootstrapBeforeSetup();
    $second = bootstrapBeforeSetup();

    $team = Team::query()->sole();

    expect($first['token'])->toMatch('/^lr_jt_[A-Za-z0-9_-]{43}$/')
        ->and($second['token'])->toMatch('/^lr_jt_/')
        ->and($team->memberships()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditAction::TeamCreated->value)->count())->toBe(1);
});

test('the owner claims the instance team, with the control-plane server in it', function (): void {
    $daemon = new FakeDaemon;
    $daemon->send($this, 'POST', '/daemon/v1/enroll', $daemon->enrollBody(bootstrapBeforeSetup()['token']))->assertCreated();

    Artisan::call('launchroom:setup-token', ['--plain' => true]);
    $this->post(route('setup.store'), [
        'token' => trim(Artisan::output()),
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard'));

    $owner = User::query()->sole();
    $team = Team::query()->sole();

    expect($owner->current_team_id)->toBe($team->id)
        ->and($owner->roleIn($team))->toBe(TeamRole::Owner)
        ->and($team->name)->toBe('Ada’s team')
        ->and($team->personal)->toBeTrue()
        ->and(Server::query()->sole()->team_id)->toBe($team->id)
        ->and(AuditEvent::query()->where('action', AuditAction::InstanceTeamClaimed->value)->count())->toBe(1);

    // After setup, bootstrap-local reports the enrolled server of the owner's team.
    expect(bootstrapBeforeSetup()['serverId'])->toBe(Server::query()->sole()->public_id);
});

test('without an installer team, setup still creates a new personal team', function (): void {
    Artisan::call('launchroom:setup-token', ['--plain' => true]);
    $this->post(route('setup.store'), [
        'token' => trim(Artisan::output()),
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard'));

    expect(Team::query()->sole()->memberships()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditAction::InstanceTeamClaimed->value)->count())->toBe(0);
});

test('reset-owner-2fa removes the owner\'s 2FA and is audited', function (): void {
    $owner = User::factory()->instanceOwner()->create(['email' => 'owner@example.com']);
    $owner->forceFill(['two_factor_secret' => 'secret', 'two_factor_recovery_codes' => 'codes', 'two_factor_confirmed_at' => now()])->save();

    $this->artisan('launchroom:owner:reset-2fa', ['email' => 'Owner@Example.com'])->assertSuccessful();

    $owner->refresh();
    expect($owner->hasTwoFactorEnabled())->toBeFalse()
        ->and($owner->two_factor_secret)->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::OwnerTwoFactorReset->value)->sole()->subject_id)->toBe((string) $owner->id);
});

test('reset-owner-2fa only touches instance owners', function (): void {
    $member = User::factory()->create(['email' => 'member@example.com']);
    $member->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->artisan('launchroom:owner:reset-2fa', ['email' => 'member@example.com'])->assertFailed();

    expect($member->refresh()->hasTwoFactorEnabled())->toBeTrue();
});
