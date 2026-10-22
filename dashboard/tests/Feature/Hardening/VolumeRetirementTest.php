<?php

declare(strict_types=1);

use App\Actions\Backups\RunBackupTick;
use App\Actions\Servers\RecordReport;
use App\Actions\Servers\RenderNodeState;
use App\Data\Protocol\V1\Report;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\TeamRole;
use App\Enums\VolumeRetirementStatus;
use App\Models\AuditEvent;
use App\Models\Backups\BackupRun;
use App\Models\Backups\DeletionRequest;
use App\Models\Server;
use App\Models\Volume;
use App\Models\VolumeRetirement;
use App\Services\NodeStates\NodeStateValidator;
use Tests\Support\Backups;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;

/*
| Volume lifecycle (docs/05 §1.1): volumes of deleted projects, environments
| and previews are retained, then deleted through an explicit NodeState
| request, never because they left the desired state.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    [$this->team, $this->server, , $this->database] = Backups::runningPostgres();
    $this->project = $this->database->environment->project;
    $this->volume = Volume::query()->where('service_instance_id', $this->database->id)->sole();
    $this->admin = memberOf($this->team, TeamRole::Admin);
    $this->deleteProject = fn (array $data = []) => $this->actingAs($this->admin)
        ->delete(route('projects.destroy', $this->project), ['confirmName' => $this->project->name, ...$data]);
});

function reportVolumeDeletions(Server $server, array $deletions): void
{
    resolve(RecordReport::class)->handle($server, Report::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'Report', 'serverId' => $server->public_id,
        'observedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'seq' => (int) (microtime(true) * 1_000_000), 'full' => true,
        'appliedVersion' => $server->refresh()->desired_version, 'workloads' => [], 'volumeDeletions' => $deletions,
    ]));
}

function renderedDeletions(Server $server): array
{
    $document = resolve(RenderNodeState::class)->handle($server)->version->document;

    expect(ProtocolSchemas::errors('node-state', $document))->toBe([])
        ->and(resolve(NodeStateValidator::class)->problems($document))->toBe([]);

    return json_decode($document, true, flags: JSON_THROW_ON_ERROR)['deleteVolumes'] ?? [];
}

test('a deleted project’s volume is retained, then deleted on request, never on mere absence', function (): void {
    ($this->deleteProject)()->assertRedirect(route('projects.index'));

    $retirement = VolumeRetirement::query()->sole();
    expect($retirement->volume_public_id)->toBe($this->volume->public_id)
        ->and($retirement->docker_name)->toBe($this->volume->dockerName())
        ->and($retirement->status)->toBe(VolumeRetirementStatus::Retained)
        ->and($retirement->reason)->toBe('project_deleted')
        ->and($retirement->retain_until->diffInHours(now()->addDays(7), true))->toBeLessThan(1.0)
        // Gone from the desired state, but nothing asks the daemon to delete it.
        ->and(renderedDeletions($this->server))->toBe([]);

    $this->artisan('launchroom:volumes:retire')->assertSuccessful();
    expect($retirement->refresh()->status)->toBe(VolumeRetirementStatus::Retained);

    $this->travel(7)->days();
    $this->travel(1)->hours();
    $this->artisan('launchroom:volumes:retire')->assertSuccessful();

    expect($retirement->refresh()->status)->toBe(VolumeRetirementStatus::Requested)
        ->and(renderedDeletions($this->server))->toBe([[
            'id' => $this->volume->public_id,
            'name' => $this->volume->dockerName(),
            'service' => $retirement->service_public_id,
            'environment' => $retirement->environment_public_id,
            'requestedAt' => $retirement->requested_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ]]);

    // Refused (e.g. still in use): stays requested with the daemon's reason.
    reportVolumeDeletions($this->server, [['id' => $this->volume->public_id, 'name' => $this->volume->dockerName(), 'outcome' => 'refused', 'message' => 'the volume is in use by lr-x', 'at' => now()->utc()->format('Y-m-d\TH:i:s\Z')]]);
    expect($retirement->refresh()->status)->toBe(VolumeRetirementStatus::Requested)
        ->and($retirement->message)->toBe('the volume is in use by lr-x');

    reportVolumeDeletions($this->server, [['id' => $this->volume->public_id, 'name' => $this->volume->dockerName(), 'outcome' => 'deleted', 'at' => now()->utc()->format('Y-m-d\TH:i:s\Z')]]);
    expect($retirement->refresh()->status)->toBe(VolumeRetirementStatus::Deleted)
        ->and($retirement->deleted_at)->not->toBeNull()
        ->and(renderedDeletions($this->server))->toBe([]);
});

test('a backed-up volume records its final backup and is deleted after the retention period (docs/14 §9.13)', function (): void {
    Backups::destination($this->team, $this->admin, makeDefault: true);

    ($this->deleteProject)()->assertRedirect();
    $final = BackupRun::query()->whereKey(DeletionRequest::query()->sole()->backup_run_ids)->sole();
    $final->transitionTo(BackupRunStatus::Running);
    $final->forceFill(['snapshot_id' => str_repeat('ef', 32)]);
    $final->transitionTo(BackupRunStatus::Succeeded);
    resolve(RunBackupTick::class)->handle();

    $retirement = VolumeRetirement::query()->sole();
    expect($retirement->backups_configured)->toBeTrue()
        ->and($retirement->final_backup_snapshot)->toBe(str_repeat('ef', 32))
        ->and($retirement->confirmed_at)->toBeNull();

    $this->travel(7)->days();
    $this->travel(1)->hours();
    $this->artisan('launchroom:volumes:retire')->assertSuccessful();
    expect($retirement->refresh()->status)->toBe(VolumeRetirementStatus::Requested);
});

test('a backed-up volume retired without a final backup is kept until an admin allows its deletion on the server page', function (): void {
    $retirement = VolumeRetirement::query()->create([
        'team_id' => $this->team->id, 'server_id' => $this->server->id, 'volume_public_id' => 'vol_01J9Z8X7W6V5T4S3R2Q1P0N9Z1',
        'docker_name' => 'lr-v_00000000000000aa', 'service_public_id' => 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9Z2',
        'environment_public_id' => 'env_01J9Z8X7W6V5T4S3R2Q1P0N9Z3', 'label' => 'db in shop / pr-7', 'reason' => 'preview_destroyed',
        'mount_path' => '/var/lib/postgresql', 'retain_until' => now()->subMinute(), 'backups_configured' => true,
    ]);

    $this->artisan('launchroom:volumes:retire')->assertSuccessful();
    expect($retirement->refresh()->status)->toBe(VolumeRetirementStatus::Retained);

    $this->actingAs($this->admin)->get(route('servers.tab', [$this->server, 'settings']))
        ->assertInertia(fn ($page) => $page->where('retiredVolumes.0.id', 'vol_01J9Z8X7W6V5T4S3R2Q1P0N9Z1')
            ->where('retiredVolumes.0.needsConfirmation', true)
            ->where('canConfirmVolumeDeletion', true));

    $url = route('servers.retired-volumes.confirm', [$this->server, 'vol_01J9Z8X7W6V5T4S3R2Q1P0N9Z1']);
    $this->actingAs(memberOf($this->team, TeamRole::Developer))->post($url)->assertForbidden();
    $this->actingAs($this->admin)->post($url)->assertRedirect();

    expect($retirement->refresh()->confirmed_by)->toBe($this->admin->id)
        ->and(AuditEvent::query()->where('action', AuditAction::VolumeDeletionConfirmed->value)->exists())->toBeTrue();

    $this->artisan('launchroom:volumes:retire')->assertSuccessful();
    expect($retirement->refresh()->status)->toBe(VolumeRetirementStatus::Requested);
});

test('preview volumes can be deleted at the next sweep', function (): void {
    config()->set('launchroom.volumes.retention_hours', 0);
    ($this->deleteProject)()->assertRedirect();

    $this->artisan('launchroom:volumes:retire')->assertSuccessful();
    expect(VolumeRetirement::query()->sole()->status)->toBe(VolumeRetirementStatus::Requested);
});
