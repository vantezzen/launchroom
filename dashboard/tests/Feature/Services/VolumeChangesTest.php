<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Deployments\SweepDeployments;
use App\Actions\Environments\DiscardChanges;
use App\Data\Audit\AuditActor;
use App\Data\Specs\ChangeItem;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Backups\BackupRun;
use App\Models\Environment;
use App\Models\Volume;
use App\Models\VolumeRetirement;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Volumes\VolumeRetirements;
use App\Support\Changes\SpecDiff;
use Tests\Support\Backups;
use Tests\Support\Projects;
use Tests\Support\VolumeServices;

/*
| Changing and removing service volumes (docs/14 §9.12): both are staged.
| Running containers mount what their deployment recorded, so a new path or
| a removal applies with the next deploy; a removed volume is then retired
| like the volumes of a deleted environment (docs/05 §1.1).
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    [$this->team, $this->server, , $this->svc] = VolumeServices::runningApp();
    $this->svc->load('service', 'environment.project');
    $this->volume = Volume::query()->where('service_instance_id', $this->svc->id)->sole();
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->route = fn (string $name): string => route($name, [$this->svc->environment->project, $this->svc->environment, $this->svc->service, $this->volume->public_id]);
    $this->mounts = fn (): array => Projects::nodeState($this->server->refresh())['workloads'][0]['mounts'] ?? [];
    $this->staged = function (): array {
        $environment = Environment::query()->with('lastAppliedRevision')->findOrFail($this->svc->environment_id);

        return array_map(static fn (ChangeItem $item): string => $item->label, SpecDiff::between($environment->lastAppliedRevision?->spec, resolve(CurrentEnvironmentSpec::class)->get($environment))->items);
    };
    $this->deploy = function (): void {
        resolve(DeployEnvironment::class)->handle($this->svc->environment->refresh(), null, AuditActor::system());
        Projects::report($this->server);
        Projects::report($this->server);
    };
    // The replaced container drains, exits and leaves the desired state.
    $this->drain = function (): void {
        $this->travel(10)->minutes();
        Projects::report($this->server);
        resolve(SweepDeployments::class)->handle();
    };
});

test('a new mount path is staged: the running containers keep the old one until the deploy', function (): void {
    $this->actingAs(memberOf($this->team, TeamRole::Viewer))->patch(($this->route)('projects.services.volumes.update'), ['mountPath' => '/srv/files'])->assertForbidden();
    $this->actingAs($this->developer)->patch(($this->route)('projects.services.volumes.update'), ['mountPath' => '/proc/files'])->assertSessionHasErrors('mountPath');
    $this->actingAs($this->developer)->patch(($this->route)('projects.services.volumes.update'), ['mountPath' => 'relative'])->assertSessionHasErrors('mountPath');

    $this->actingAs($this->developer)->patch(($this->route)('projects.services.volumes.update'), ['mountPath' => '/srv/files'])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->volume->refresh()->mount_path)->toBe('/srv/files')
        ->and(($this->staged)())->toContain('uploads: volume /srv/files changed')
        ->and(($this->mounts)())->toBe([['volume' => $this->volume->public_id, 'target' => '/data/uploads']])
        ->and(AuditEvent::query()->where('action', AuditAction::ServiceVolumeChanged->value)->sole()->meta)->toMatchArray(['from' => '/data/uploads', 'to' => '/srv/files']);

    $this->actingAs($this->developer)->get(route('projects.services.show', [$this->svc->environment->project, $this->svc->environment, $this->svc->service, 'settings']))
        ->assertInertia(fn ($page) => $page
            ->where('panel.settings.volumes.0.mountPath', '/srv/files')
            ->where('panel.settings.volumes.0.deployedMountPath', '/data/uploads')
            ->where('panel.settings.volumes.0.removing', false));

    ($this->deploy)();
    ($this->drain)();

    expect(($this->mounts)())->toBe([['volume' => $this->volume->public_id, 'target' => '/srv/files']])
        ->and(($this->staged)())->toBe([]);
});

test('discarding a staged mount path puts the old one back', function (): void {
    $this->actingAs($this->developer)->patch(($this->route)('projects.services.volumes.update'), ['mountPath' => '/srv/files'])->assertSessionHasNoErrors();

    resolve(DiscardChanges::class)->handle($this->svc->environment->refresh(), null, AuditActor::system());

    expect($this->volume->refresh()->mount_path)->toBe('/data/uploads');
});

test('removing a volume with files needs its path typed and applies with the next deploy', function (): void {
    $this->actingAs($this->developer)->delete(($this->route)('projects.services.volumes.destroy'), ['confirm' => '/data'])->assertSessionHasErrors('confirm');
    expect($this->volume->refresh()->removed_at)->toBeNull();

    $this->actingAs($this->developer)->delete(($this->route)('projects.services.volumes.destroy'), ['confirm' => '/data/uploads'])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->volume->refresh()->removed_at)->not->toBeNull()
        ->and(($this->staged)())->toContain('uploads: volume /data/uploads removed')
        // Still mounted by the running deployment.
        ->and(($this->mounts)())->toBe([['volume' => $this->volume->public_id, 'target' => '/data/uploads']]);

    $this->actingAs($this->developer)->get(route('projects.services.show', [$this->svc->environment->project, $this->svc->environment, $this->svc->service, 'settings']))
        ->assertInertia(fn ($page) => $page->where('panel.settings.volumes.0.removing', true));

    // The same path can't be taken while the removal is pending.
    $this->actingAs($this->developer)->post(route('projects.services.volumes.store', [$this->svc->environment->project, $this->svc->environment, $this->svc->service]), ['mountPath' => '/data/uploads'])
        ->assertSessionHasErrors(['mountPath' => 'The volume at /data/uploads is removed with the next deploy. To keep it and its files, discard that change instead.']);

    // Not retired while a deployment still mounts it.
    expect(resolve(VolumeRetirements::class)->retireRemoved())->toBe(0);

    ($this->deploy)();

    // The replaced container still mounts it while it drains.
    expect(resolve(VolumeRetirements::class)->retireRemoved())->toBe(0);

    ($this->drain)();

    $state = Projects::nodeState($this->server->refresh());
    expect($state['workloads'][0]['mounts'] ?? [])->toBe([])
        ->and($state['volumes'])->toBe([]);

    expect(resolve(VolumeRetirements::class)->retireRemoved())->toBe(1);

    $retirement = VolumeRetirement::query()->sole();
    expect($retirement->volume_public_id)->toBe($this->volume->public_id)
        ->and($retirement->reason)->toBe('volume_removed')
        ->and($retirement->docker_name)->toBe($this->volume->dockerName())
        ->and(Volume::query()->whereKey($this->volume->id)->exists())->toBeFalse();
});

test('discarding a staged removal keeps the volume', function (): void {
    $this->actingAs($this->developer)->delete(($this->route)('projects.services.volumes.destroy'), ['confirm' => '/data/uploads'])->assertSessionHasNoErrors();

    resolve(DiscardChanges::class)->handle($this->svc->environment->refresh(), null, AuditActor::system());

    expect($this->volume->refresh()->removed_at)->toBeNull()
        ->and(($this->staged)())->toBe([]);
});

test('a volume that was never created is removed right away', function (): void {
    $this->actingAs($this->developer)->post(route('projects.services.volumes.store', [$this->svc->environment->project, $this->svc->environment, $this->svc->service]), ['mountPath' => '/srv/cache'])->assertSessionHasNoErrors();
    $added = Volume::query()->where('mount_path', '/srv/cache')->sole();

    $this->actingAs($this->developer)->delete(route('projects.services.volumes.destroy', [$this->svc->environment->project, $this->svc->environment, $this->svc->service, $added->public_id]))->assertSessionHasNoErrors();

    expect(Volume::query()->whereKey($added->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::ServiceVolumeRemoved->value)->sole()->meta)->toMatchArray(['staged' => false]);
});

test('a volume a backup is reading can’t be changed or removed', function (): void {
    BackupRun::query()->forceCreate([
        'team_id' => $this->team->id,
        'destination_id' => Backups::destination($this->team, memberOf($this->team, TeamRole::Owner))->id,
        'service_instance_id' => $this->svc->id,
        'volume_id' => $this->volume->id,
        'subject' => BackupSubject::Volume,
        'reason' => BackupReason::Manual,
        'status' => BackupRunStatus::Running,
        'server_id' => $this->server->id,
    ]);

    $this->actingAs($this->developer)->delete(($this->route)('projects.services.volumes.destroy'), ['confirm' => '/data/uploads'])
        ->assertSessionHasErrors(['volume' => 'A backup of this volume is running. Try again once it has finished.']);
    $this->actingAs($this->developer)->patch(($this->route)('projects.services.volumes.update'), ['mountPath' => '/srv/files'])
        ->assertSessionHasErrors('mountPath');

    expect($this->volume->refresh()->removed_at)->toBeNull()
        ->and($this->volume->mount_path)->toBe('/data/uploads');
});

test('a database volume is managed by launchroom', function (): void {
    $database = Projects::postgres($this->svc->environment->project);
    $instance = $database->instances()->sole();
    $volume = Volume::query()->where('service_instance_id', $instance->id)->sole();
    $args = [$this->svc->environment->project, $this->svc->environment, $database, $volume->public_id];

    $this->actingAs($this->developer)->patch(route('projects.services.volumes.update', $args), ['mountPath' => '/srv/pg'])->assertSessionHasErrors('mountPath');
    $this->actingAs($this->developer)->delete(route('projects.services.volumes.destroy', $args))->assertSessionHasErrors('volume');

    expect(Volume::query()->whereKey($volume->id)->exists())->toBeTrue();
});

test('a volume of another service is not found', function (): void {
    $other = Projects::image($this->svc->environment->project, 'other');

    $this->actingAs($this->developer)->delete(route('projects.services.volumes.destroy', [$this->svc->environment->project, $this->svc->environment, $other, $this->volume->public_id]))->assertNotFound();
});
