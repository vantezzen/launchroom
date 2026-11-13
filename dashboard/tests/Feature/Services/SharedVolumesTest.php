<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Deployments\SweepDeployments;
use App\Actions\Environments\DiscardChanges;
use App\Actions\Servers\RenderNodeState;
use App\Actions\Services\AddServiceVolume;
use App\Data\Audit\AuditActor;
use App\Data\Canvas\CanvasVolumeData;
use App\Data\Canvas\CanvasVolumeMountData;
use App\Data\Services\AddVolumeData;
use App\Data\Specs\ChangeItem;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Models\VolumeMount;
use App\Queries\CanvasGraph;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Volumes\MountedVolumes;
use App\Services\Volumes\StagedVolumes;
use App\Support\Changes\SpecDiff;
use Tests\Support\Backups;
use Tests\Support\Projects;
use Tests\Support\VolumeServices;

/*
| Shared volumes (D73): a volume of one service mounted by another service
| of the same environment, at its own path. Staged like every other
| setting; every service that mounts a volume runs on the server that
| holds it, and launchroomd is told which service the volume belongs to.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    [$this->team, $this->server, , $this->owner] = VolumeServices::runningApp();
    $this->server->forceFill(['daemon_features' => ['sharedVolumes']])->save();
    $this->owner->load('service', 'environment.project');
    $this->environment = $this->owner->environment;
    $this->project = $this->environment->project;
    $this->volume = Volume::query()->where('service_instance_id', $this->owner->id)->sole();

    $reader = Projects::image($this->project, 'reader', port: null);
    $this->reader = ServiceInstance::query()->where('service_id', $reader->id)->where('environment_id', $this->environment->id)->sole();
    $this->reader->load('service');
    resolve(DeployEnvironment::class)->handle($this->environment->refresh(), null, AuditActor::system());
    Projects::report($this->server);
    Projects::report($this->server);

    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->route = fn (string $name, ?ServiceInstance $service = null, ?string $volume = null): string => route($name, [$this->project, $this->environment, ($service ?? $this->reader)->service, $volume ?? $this->volume->public_id]);
    $this->workload = fn (ServiceInstance $instance): ?array => collect(Projects::nodeState($this->server->refresh())['workloads'])
        ->first(fn (array $workload): bool => $workload['service'] === $instance->service->public_id);
    $this->staged = function (): array {
        $environment = Environment::query()->with('lastAppliedRevision')->findOrFail($this->environment->id);

        return array_map(static fn (ChangeItem $item): string => $item->label, SpecDiff::between($environment->lastAppliedRevision?->spec, resolve(CurrentEnvironmentSpec::class)->get($environment))->items);
    };
    $this->deploy = function (): void {
        resolve(DeployEnvironment::class)->handle($this->environment->refresh(), null, AuditActor::system());
        Projects::report($this->server);
        Projects::report($this->server);
        $this->travel(10)->minutes();
        Projects::report($this->server);
        resolve(SweepDeployments::class)->handle();
    };
});

test('another service mounts the volume at its own path with the next deploy', function (): void {
    $this->actingAs(memberOf($this->team, TeamRole::Viewer))->post(($this->route)('projects.services.volume-mounts.store'), ['mountPath' => '/srv/files'])->assertForbidden();
    $this->actingAs($this->developer)->post(($this->route)('projects.services.volume-mounts.store'), ['mountPath' => '/proc/files'])->assertSessionHasErrors('mountPath');

    $this->actingAs($this->developer)->getJson(($this->route)('projects.services.volume-mounts.preview'))
        ->assertOk()
        ->assertJson(['problem' => null, 'suggestedPath' => '/data/uploads']);

    $this->actingAs($this->developer)->post(($this->route)('projects.services.volume-mounts.store'), ['mountPath' => '/srv/files'])->assertRedirect()->assertSessionHasNoErrors();

    expect(VolumeMount::query()->sole()->only(['volume_id', 'service_instance_id', 'mount_path']))->toBe(['volume_id' => $this->volume->id, 'service_instance_id' => $this->reader->id, 'mount_path' => '/srv/files'])
        ->and(($this->staged)())->toContain('reader: volume /srv/files added')
        ->and(($this->workload)($this->reader)['mounts'] ?? null)->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::ServiceVolumeAttached->value)->sole()->meta)->toMatchArray(['volume' => $this->volume->public_id, 'mountPath' => '/srv/files']);

    // Mounting it twice is refused.
    $this->actingAs($this->developer)->post(($this->route)('projects.services.volume-mounts.store'), ['mountPath' => '/srv/other'])->assertSessionHasErrors(['mountPath' => 'reader already uses this volume.']);

    ($this->deploy)();

    $state = Projects::nodeState($this->server->refresh());

    expect(($this->workload)($this->reader)['mounts'])->toBe([['volume' => $this->volume->public_id, 'target' => '/srv/files']])
        ->and(($this->workload)($this->owner)['mounts'])->toBe([['volume' => $this->volume->public_id, 'target' => '/data/uploads']])
        // Declared once, with the service it belongs to.
        ->and($state['volumes'])->toBe([['id' => $this->volume->public_id, 'name' => $this->volume->dockerName(), 'service' => $this->owner->service->public_id]])
        ->and(($this->staged)())->toBe([])
        ->and(MountedVolumes::for($this->reader->refresh())->modelKeys())->toBe([$this->volume->id]);
});

test('a server that can’t share volumes is refused before anything is staged', function (): void {
    $this->server->forceFill(['daemon_features' => []])->save();

    $this->actingAs($this->developer)->getJson(($this->route)('projects.services.volume-mounts.preview'))
        ->assertOk()
        ->assertJsonPath('problem', "{$this->server->name} runs an older version of launchroom that can’t share volumes. Update it first.");
    $this->actingAs($this->developer)->post(($this->route)('projects.services.volume-mounts.store'), ['mountPath' => '/srv/files'])->assertSessionHasErrors('mountPath');

    expect(VolumeMount::query()->count())->toBe(0);
});

test('a database volume is never shared and a volume never leaves its environment', function (): void {
    $postgres = Projects::postgres($this->project);
    $database = ServiceInstance::query()->where('service_id', $postgres->id)->where('environment_id', $this->environment->id)->sole();
    $databaseVolume = Volume::query()->create(['team_id' => $this->team->id, 'service_instance_id' => $database->id, 'mount_path' => '/var/lib/postgresql/data']);

    $this->actingAs($this->developer)->post(($this->route)('projects.services.volume-mounts.store', volume: $databaseVolume->public_id), ['mountPath' => '/srv/db'])
        ->assertSessionHasErrors(['mountPath' => 'A database keeps its data on this volume, so it can’t be shared. Connect to the database instead.']);

    // A database can't mount one either.
    $this->actingAs($this->developer)->post(($this->route)('projects.services.volume-mounts.store', $database), ['mountPath' => '/srv/files'])
        ->assertSessionHasErrors('mountPath');

    // A volume of another environment is not found from this one.
    $other = Projects::create($this->team, 'other');
    $otherService = Projects::image($other, 'files');
    $otherInstance = ServiceInstance::query()->where('service_id', $otherService->id)->sole();
    $elsewhere = resolve(AddServiceVolume::class)->handle($otherInstance, new AddVolumeData('/data/uploads'), AuditActor::system());

    $this->actingAs($this->developer)->post(($this->route)('projects.services.volume-mounts.store', volume: $elsewhere->public_id), ['mountPath' => '/srv/files'])->assertNotFound();

    expect(VolumeMount::query()->count())->toBe(0);
});

test('services that share a volume run on its server, and data on two servers can’t be joined', function (): void {
    [$second] = Projects::server($this->team, ip: '203.0.113.8');
    $second->forceFill(['daemon_features' => ['sharedVolumes']])->save();
    Placement::query()->where('service_instance_id', $this->reader->id)->update(['server_id' => $second->id]);

    $preview = $this->actingAs($this->developer)->getJson(($this->route)('projects.services.volume-mounts.preview'))->assertOk()->json();

    expect($preview['problem'])->toBeNull()
        ->and($preview['notices'])->toContain("reader runs on {$second->name}. With the next deploy it moves to {$this->server->name}, where this volume is kept.");

    // reader keeps its own data on the second server: the two can't meet.
    resolve(AddServiceVolume::class)->handle($this->reader, new AddVolumeData('/data/own'), AuditActor::system());
    Volume::query()->where('service_instance_id', $this->reader->id)->update(['server_id' => $second->id]);

    $this->actingAs($this->developer)->post(($this->route)('projects.services.volume-mounts.store'), ['mountPath' => '/srv/files'])
        ->assertSessionHasErrors(['mountPath' => "reader keeps data on {$second->name}, and this volume is on {$this->server->name}. A volume stays on one server, so services that share it run on the same server."]);

    // Joined anyway (rows changed underneath): placement fails closed.
    VolumeMount::query()->create(['team_id' => $this->team->id, 'volume_id' => $this->volume->id, 'service_instance_id' => $this->reader->id, 'mount_path' => '/srv/files']);

    expect(MountedVolumes::apart($this->reader->refresh()))->toContain('kept on different servers');
});

test('detaching is staged: the running container keeps the volume until the deploy, a discard brings it back', function (): void {
    $this->actingAs($this->developer)->post(($this->route)('projects.services.volume-mounts.store'), ['mountPath' => '/srv/files'])->assertSessionHasNoErrors();
    ($this->deploy)();

    $this->actingAs($this->developer)->delete(($this->route)('projects.services.volume-mounts.destroy'))->assertRedirect()->assertSessionHasNoErrors();

    expect(VolumeMount::query()->count())->toBe(0)
        ->and(($this->staged)())->toContain('reader: volume /srv/files removed')
        ->and(($this->workload)($this->reader)['mounts'])->toBe([['volume' => $this->volume->public_id, 'target' => '/srv/files']])
        ->and(StagedVolumes::mounted($this->volume))->toBeTrue()
        ->and(AuditEvent::query()->where('action', AuditAction::ServiceVolumeDetached->value)->count())->toBe(1);

    // The canvas still draws the mount, as leaving.
    $volumes = resolve(CanvasGraph::class)->for($this->environment->refresh())['volumes'];
    expect($volumes)->toHaveCount(1)
        ->and(collect($volumes[0]->mounts)->firstWhere('serviceId', $this->reader->service->public_id)?->removing)->toBeTrue();

    resolve(DiscardChanges::class)->handle($this->environment->refresh(), null, AuditActor::system());

    expect(VolumeMount::query()->sole()->mount_path)->toBe('/srv/files')
        ->and(($this->staged)())->toBe([]);

    // Detached for real this time.
    $this->actingAs($this->developer)->delete(($this->route)('projects.services.volume-mounts.destroy'))->assertSessionHasNoErrors();
    ($this->deploy)();

    expect(($this->workload)($this->reader)['mounts'] ?? null)->toBeNull()
        ->and(Projects::nodeState($this->server->refresh())['volumes'])->toBe([['id' => $this->volume->public_id, 'name' => $this->volume->dockerName()]]);
});

test('the canvas shows the volume as a node with every service that mounts it', function (): void {
    $this->actingAs($this->developer)->post(($this->route)('projects.services.volume-mounts.store'), ['mountPath' => '/srv/files'])->assertSessionHasNoErrors();

    /** @var list<CanvasVolumeData> $volumes */
    $volumes = resolve(CanvasGraph::class)->for($this->environment->refresh())['volumes'];

    expect($volumes)->toHaveCount(1)
        ->and($volumes[0]->id)->toBe($this->volume->public_id)
        ->and($volumes[0]->ownerId)->toBe($this->owner->service->public_id)
        ->and($volumes[0]->server)->toBe($this->server->name)
        ->and(array_map(static fn (CanvasVolumeMountData $mount): array => [$mount->mountPath, $mount->owner, $mount->staged], $volumes[0]->mounts))
        ->toBe([['/data/uploads', true, false], ['/srv/files', false, true]]);

    // The owner's Volumes settings say who else uses it.
    $this->actingAs($this->developer)->get(route('projects.services.section', [$this->project, $this->environment, $this->owner->service, 'volumes']))
        ->assertInertia(fn ($page) => $page->where('panel.settings.volumes.0.sharedWith', ['reader']));

    // Volume nodes keep their place like services.
    $this->actingAs($this->developer)->putJson(route('projects.layout', [$this->project, $this->environment]), ['positions' => [$this->volume->public_id => ['x' => 640, 'y' => 120]]])->assertNoContent();

    expect(resolve(CanvasGraph::class)->for($this->environment->refresh()->load('canvasLayout'))['volumes'][0]->position?->x)->toBe(640.0);
});

test('restores and imports find the services that share a volume', function (): void {
    VolumeMount::query()->create(['team_id' => $this->team->id, 'volume_id' => $this->volume->id, 'service_instance_id' => $this->reader->id, 'mount_path' => '/srv/files']);

    expect(MountedVolumes::borrowersOf([$this->volume->id]))->toBe([$this->reader->id])
        ->and(MountedVolumes::borrowerIds($this->volume))->toBe([$this->reader->id]);
});

test('a workload whose shared volume lives on another server is left out', function (): void {
    VolumeMount::query()->create(['team_id' => $this->team->id, 'volume_id' => $this->volume->id, 'service_instance_id' => $this->reader->id, 'mount_path' => '/srv/files']);
    ($this->deploy)();
    expect(($this->workload)($this->reader))->not->toBeNull();

    [$second] = Projects::server($this->team, ip: '203.0.113.9');
    Volume::query()->whereKey($this->volume->id)->update(['server_id' => $second->id]);
    resolve(RenderNodeState::class)->handle($this->server->refresh());

    expect(($this->workload)($this->reader))->toBeNull()
        ->and(($this->workload)($this->owner))->not->toBeNull();
});
