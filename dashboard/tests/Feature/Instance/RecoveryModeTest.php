<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\ServerReplacement;
use App\Models\Team;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Backups;
use Tests\Support\Projects;

/*
| Recovery mode (docs/14 §5.2, §9.15): `install.sh --restore` enrolls the new
| server with `bootstrap-local --force --recovery`; the dashboard opens with
| a checklist that replaces the previous control-plane server.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    config(['inertia.ssr.enabled' => false]);
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->owner->forceFill(['is_instance_owner' => true])->save();
    [$this->previous] = Projects::server($this->team);
    $this->previous->forceFill(['is_control_plane' => true, 'name' => 'old-main', 'labels' => ['tier' => 'main']])->save();
});

function enrollRecovered(): array
{
    Artisan::call('launchroom:servers:bootstrap-local', ['--force' => true, '--recovery' => true]);

    return json_decode(trim(Artisan::output()), true, 8, JSON_THROW_ON_ERROR);
}

test('bootstrap-local --recovery keeps the previous control-plane server for the checklist and enters recovery mode', function (): void {
    $output = enrollRecovered();

    expect($output['token'])->toBeString()
        ->and($this->previous->refresh()->status)->toBe(ServerStatus::Online)
        ->and($this->previous->is_control_plane)->toBeFalse()
        ->and(InstanceSettings::current()->recovery_state['previousControlPlaneServerId'] ?? null)->toBe($this->previous->id);

    $this->actingAs($this->owner)->get(route('dashboard'))->assertRedirect(route('recovery.show'));
    $this->actingAs($this->owner)->get(route('projects.index'))->assertInertia(fn ($page) => $page->where('recoveryMode', true));

    $member = memberOf($this->team, TeamRole::Admin);
    $this->actingAs($member)->get(route('dashboard'))->assertRedirect(route('projects.index'));
    $this->actingAs($member)->get(route('recovery.show'))->assertForbidden();
});

test('the checklist replaces the previous control-plane server with the new one, then finishes', function (): void {
    enrollRecovered();
    $this->actingAs($this->owner)->get(route('recovery.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('recovery')
            ->where('active', true)
            ->where('previous.id', $this->previous->public_id)
            ->where('current', null));

    [$current] = Projects::server($this->team, [ServerRole::Edge, ServerRole::Runtime, ServerRole::Build], ip: '198.51.100.20');
    $current->forceFill(['is_control_plane' => true, 'name' => 'new-main'])->save();

    $this->actingAs($this->owner)->get(route('recovery.show'))
        ->assertInertia(fn ($page) => $page->where('current.id', $current->public_id)->where('replacement', null));

    // The old box is still "online" in the restored database: recovery replaces it anyway.
    $this->actingAs($this->owner)->post(route('recovery.replace'))->assertRedirect(route('password.confirm'));
    $this->actingAs($this->owner)->withSession(passwordConfirmed())->post(route('recovery.replace'))->assertRedirect(route('recovery.show'));

    $replacement = ServerReplacement::query()->sole();
    expect($replacement->recovery)->toBeTrue()
        ->and($this->previous->refresh()->status)->toBe(ServerStatus::Revoked)
        ->and($this->previous->replaced_by_server_id)->toBe($current->id)
        ->and($current->refresh()->is_control_plane)->toBeTrue()
        ->and($current->labels)->toBe(['tier' => 'main'])
        ->and(InstanceSettings::current()->recovery_state['replacementId'] ?? null)->toBe($replacement->id);

    $this->actingAs($this->owner)->get(route('recovery.show'))
        ->assertInertia(fn ($page) => $page->where('replacement.id', $replacement->public_id));

    $this->actingAs($this->owner)->post(route('recovery.finish'), ['open' => ['dns']])->assertRedirect(route('servers.index'));

    expect(InstanceSettings::current()->recovery_state)->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::RecoveryFinished->value)->sole()->meta)->toMatchArray(['open' => ['dns']]);
    $this->actingAs($this->owner)->get('/')->assertRedirect();
    $this->actingAs($this->owner)->get(route('projects.index'))->assertInertia(fn ($page) => $page->where('recoveryMode', false));
});

test('outside recovery mode the previous control-plane server is revoked on --force as before', function (): void {
    Artisan::call('launchroom:servers:bootstrap-local', ['--force' => true]);

    expect($this->previous->refresh()->status)->toBe(ServerStatus::Revoked)
        ->and(InstanceSettings::current()->recovery_state)->toBeNull();

    $this->actingAs($this->owner)->withSession(passwordConfirmed())->post(route('recovery.replace'))->assertStatus(409);
});

test('the recovery helpers show which servers reconnected and which images are missing', function (): void {
    enrollRecovered();
    [$current] = Projects::server($this->team, ip: '198.51.100.20');
    $current->forceFill(['is_control_plane' => true])->save();
    [$other] = Projects::server($this->team, [ServerRole::Runtime], ip: '198.51.100.30');
    $other->forceFill(['last_seen_at' => now()->addMinute()])->save();
    Server::query()->whereKey($this->previous->id)->update(['last_seen_at' => now()->subDay()]);

    $this->actingAs($this->owner)->get(route('recovery.show', ['images' => 1]))
        ->assertInertia(fn ($page) => $page->where('servers', fn ($servers): bool => collect($servers)->firstWhere('id', $other->public_id)['reconnected'] === true)
            ->where('images.missing', []));
});
