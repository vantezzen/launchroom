<?php

declare(strict_types=1);

use App\Actions\Backups\StartServiceBackup;
use App\Actions\Connections\CreateConnection;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Moves\AdvanceServerMoves;
use App\Actions\Transfers\AdvanceImports;
use App\Data\Audit\AuditActor;
use App\Data\Servers\HostFactsData;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;
use App\Enums\Moves\MoveItemKind;
use App\Enums\Moves\MoveItemStatus;
use App\Enums\Moves\ServerMoveStatus;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\ServiceKind;
use App\Enums\TeamRole;
use App\Enums\Transfers\ImportRunStatus;
use App\Models\AuditEvent;
use App\Models\Backups\BackupRun;
use App\Models\Deployment;
use App\Models\InstanceSettings;
use App\Models\Moves\ServerMove;
use App\Models\Moves\ServerMoveItem;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Volume;
use App\Services\Moves\MovePlanner;
use App\Services\Moves\Prebuilds;
use Tests\Support\Backups;
use Tests\Support\Projects;
use Tests\Support\Transfers;
use Tests\Support\VolumeServices;
use Tests\TestCase;

/*
| Move to another server (D56, docs/14 §13.2): everything on an online
| server moves to another one while it keeps running. Stateless services
| are redeployed first (the source drains); then each database stops its
| apps, is copied through the dashboard with the D55 export/import tasks
| and starts on the target; finally the source is retired.
*/

function movable(Server $server): Server
{
    $server->forceFill(['daemon_features' => [DaemonFeature::DataTransfer->value, DaemonFeature::PreparedVolumes->value]])->save();

    return $server->refresh();
}

/**
 * Reports every server with its workloads (draining ones stop), a few times
 * so deployments and moves can follow each other.
 *
 * @param  list<Server>  $servers
 * @param  array<string, string>  $phases
 */
function settleServers(array $servers, int $rounds = 3, array $phases = []): void
{
    for ($i = 0; $i < $rounds; $i++) {
        foreach ($servers as $server) {
            Projects::report($server, phases: $phases);
        }

        resolve(AdvanceServerMoves::class)->handle();
    }
}

/**
 * launchroomd on $server leases the export, uploads $bytes and finishes it.
 */
function daemonExports(TestCase $test, Server $server, string $bytes): void
{
    $task = VolumeServices::lease($server, DaemonTaskType::DbExport);
    $spec = json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    $daemon = Transfers::daemon($server);

    foreach (str_split($bytes, Transfers::CHUNK) as $index => $chunk) {
        $daemon->send($test, 'PUT', "/daemon/v1/transfers/{$spec['transfer']['id']}/chunks/{$index}", $chunk, ['Content-Type' => 'application/octet-stream'])->assertNoContent();
    }

    VolumeServices::finish($server, $task, 'succeeded', ['dataExport' => ['format' => 'postgres_custom', 'sizeBytes' => strlen($bytes), 'chunks' => count(str_split($bytes, Transfers::CHUNK)), 'sha256' => Transfers::digest($bytes), 'durationMs' => 900]]);
}

/**
 * launchroomd stopped every workload of $instance (it was held).
 */
function stopped(ServiceInstance $instance): void
{
    $placements = Placement::query()->where('service_instance_id', $instance->id)->with('server')->get();

    foreach ($placements->groupBy('server_id') as $onServer) {
        Projects::report($onServer->first()->server, phases: $onServer->mapWithKeys(fn (Placement $placement): array => [$placement->workload_id => 'exited'])->all());
    }

    resolve(AdvanceServerMoves::class)->handle();
}

/**
 * Every workload of $instance reported stopped.
 *
 * @return array<string, string>
 */
function exited(ServiceInstance $instance): array
{
    return Placement::query()->where('service_instance_id', $instance->id)->pluck('workload_id')->mapWithKeys(fn (string $id): array => [$id => 'exited'])->all();
}

/**
 * The placement of $instance on $server, if any.
 */
function workloadOn(ServiceInstance $instance, Server $server): ?Placement
{
    return Placement::query()->where('service_instance_id', $instance->id)->where('server_id', $server->id)->first();
}

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    Transfers::useTempStore();

    $this->team = Team::factory()->create();
    [$source] = Projects::server($this->team);
    $this->source = movable($source);
    $this->source->forceFill(['name' => 'droplet', 'labels' => ['zone' => 'ams3'], 'mesh_ip' => '100.64.0.1'])->save();
    $project = Projects::create($this->team, 'shop');
    $postgres = Projects::postgres($project);
    $web = Projects::image($project, 'web');
    resolve(CreateConnection::class)->handle($web, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
    $environment = $project->production()->sole();
    resolve(DeployEnvironment::class)->handle($environment, null, AuditActor::system());

    for ($i = 0; $i < 4; $i++) {
        Projects::report($this->source);
    }

    $this->postgres = ServiceInstance::query()->where('service_id', $postgres->id)->sole();
    $this->web = ServiceInstance::query()->where('service_id', $web->id)->sole();
    [$target] = Projects::server($this->team, [ServerRole::Runtime, ServerRole::Build], ip: '203.0.113.8');
    $this->target = movable($target);
    $this->target->forceFill(['name' => 'netcup', 'mesh_ip' => '100.64.0.2'])->save();
    InstanceSettings::current()->forceFill(['mesh_enabled_at' => now()])->save();
    $this->owner = memberOf($this->team, TeamRole::Owner);
});

test('the plan moves stateless services without downtime and stops each database briefly', function (): void {
    $this->actingAs($this->owner)->get(route('servers.move', [$this->source, 'target' => $this->target->public_id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('servers/move')
            ->where('targetId', $this->target->public_id)
            ->where('plan.blockers', [])
            ->where('plan.addsEdge', true)
            ->where('plan.items.0.instanceId', $this->web->public_id)
            ->where('plan.items.0.kind', MoveItemKind::Stateless->value)
            ->where('plan.items.0.downtimeSeconds', 0)
            ->where('plan.items.1.instanceId', $this->postgres->public_id)
            ->where('plan.items.1.kind', MoveItemKind::Dump->value)
            ->where('plan.items.1.stops', ['web'])
            ->where('plan.dns', [])
            ->where('move', null));

    $this->actingAs(memberOf($this->team, TeamRole::Developer))->get(route('servers.move', $this->source))->assertForbidden();
});

test('a move is refused onto a server that can’t take it', function (): void {
    $planner = resolve(MovePlanner::class);

    // No apps there.
    $this->target->forceFill(['roles' => [ServerRole::Build->value]])->save();
    expect($planner->plan($this->source, $this->target->refresh())->blockers)->toContain('netcup doesn’t run apps. Turn on Run apps in its settings first.');

    // Too little disk for what the database's last backup weighed.
    $this->target->forceFill(['roles' => [ServerRole::Runtime->value], 'host_status' => ['diskFreeMb' => 512]])->save();
    Backups::destination($this->team, $this->owner, makeDefault: true);
    resolve(StartServiceBackup::class)->handle($this->postgres, BackupReason::Manual, $this->owner, AuditActor::user($this->owner));
    VolumeServices::backedUp($this->source, str_repeat('ab', 32));
    BackupRun::query()->update(['size_bytes' => 2_000_000_000]);
    expect(implode(' ', $planner->plan($this->source, $this->target->refresh())->blockers))->toContain('netcup has 537 MB free');

    // An old launchroomd that can't receive data.
    $this->target->forceFill(['host_status' => null, 'daemon_features' => []])->save();
    expect(implode(' ', $planner->plan($this->source, $this->target->refresh())->blockers))->toContain('Update launchroomd on netcup');

    // The dashboard's own server moves through Settings → Move launchroom.
    movable($this->target);
    $this->source->forceFill(['is_control_plane' => true])->save();
    expect(implode(' ', $planner->plan($this->source->refresh(), $this->target->refresh())->blockers))->toContain('Settings → Move launchroom');
    $this->source->forceFill(['is_control_plane' => false])->save();

    // An offline source: that is Replace server.
    $this->source->refresh()->transitionTo(ServerStatus::Offline);
    expect(implode(' ', $planner->plan($this->source->refresh(), $this->target)->blockers))->toContain('use Replace server');

    // And the form refuses what the plan refuses.
    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('servers.move.store', $this->source), ['target' => $this->target->public_id, 'confirmation' => 'droplet'])
        ->assertSessionHasErrors('target');
    expect(ServerMove::query()->exists())->toBeFalse();
});

test('the private network must be on before services move between servers', function (): void {
    InstanceSettings::current()->forceFill(['mesh_enabled_at' => null])->save();

    expect(implode(' ', resolve(MovePlanner::class)->plan($this->source, $this->target)->blockers))->toContain('private network');
});

test('everything moves: apps first, then the database with a short stop, then the source is retired', function (): void {
    $url = route('servers.move.store', $this->source);
    $this->actingAs($this->owner)->post($url, ['target' => $this->target->public_id, 'confirmation' => 'droplet'])->assertRedirect(route('password.confirm'));
    $this->actingAs($this->owner)->withSession(passwordConfirmed())->post($url, ['target' => $this->target->public_id, 'confirmation' => 'nope'])->assertSessionHasErrors('confirmation');
    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post($url, ['target' => $this->target->public_id, 'backupFirst' => false, 'confirmation' => 'droplet'])
        ->assertRedirect(route('servers.move', $this->source));

    $move = ServerMove::query()->sole();
    [$webItem, $dbItem] = ServerMoveItem::query()->orderBy('position')->get()->all();
    expect($move->status)->toBe(ServerMoveStatus::Running)
        ->and($move->edge_added)->toBeTrue()
        ->and($this->target->refresh()->hasRole(ServerRole::Edge))->toBeTrue()
        ->and($webItem->status)->toBe(MoveItemStatus::Starting)
        // The database waits until its app runs on the target.
        ->and($dbItem->status)->toBe(MoveItemStatus::Pending)
        ->and(AuditEvent::query()->where('action', AuditAction::ServerMoveStarted->value)->exists())->toBeTrue();

    // The web service starts on the target (the source drains); the old copy serves until then.
    settleServers([$this->target, $this->source]);
    expect($webItem->refresh()->status)->toBe(MoveItemStatus::Done)
        ->and(workloadOn($this->web, $this->target))->not->toBeNull()
        ->and($dbItem->refresh()->status)->toBe(MoveItemStatus::Stopping);

    // Its app is held while the database is copied.
    $webWorkload = workloadOn($this->web, $this->target);
    expect(collect(Projects::nodeState($this->target)['workloads'] ?? [])->pluck('id')->all())->not->toContain($webWorkload?->workload_id);
    stopped($this->web);
    expect($dbItem->refresh()->status)->toBe(MoveItemStatus::Exporting);

    // launchroomd on the source dumps it into the dashboard: no S3 involved.
    $bytes = Transfers::pgCustom('18.1', '18.1', Transfers::CHUNK + 321);
    daemonExports($this, $this->source, $bytes);
    resolve(AdvanceServerMoves::class)->handle();

    // Cutover: the volume belongs to the target; the database starts there empty.
    expect($dbItem->refresh()->status)->toBe(MoveItemStatus::Preparing)
        ->and($dbItem->copied_bytes)->toBe(strlen($bytes))
        ->and(Volume::query()->where('service_instance_id', $this->postgres->id)->sole()->server_id)->toBe($this->target->id)
        ->and(workloadOn($this->postgres, $this->source))->toBeNull();

    // (Its app stays stopped: launchroomd reports it so.)
    settleServers([$this->target, $this->source], phases: exited($this->web));
    expect($dbItem->refresh()->status)->toBe(MoveItemStatus::Importing);
    $import = DataImportRun::query()->sole();
    expect($import->replace)->toBeTrue()
        ->and($import->server_id)->toBe($this->target->id);
    resolve(AdvanceImports::class)->handle();
    expect($import->refresh()->status)->toBe(ImportRunStatus::Importing);

    // launchroomd on the target reads the same file through the dashboard.
    $task = VolumeServices::lease($this->target, DaemonTaskType::DbImport);
    $spec = json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    $transfer = DataExportRun::query()->sole()->transfer;
    expect($spec['source']['file']['id'])->toBe($transfer?->public_id);
    $chunk = Transfers::daemon($this->target)->send($this, 'GET', "/daemon/v1/transfers/{$transfer?->public_id}/chunks/0")->assertOk();
    expect($chunk->getContent())->toBe(substr($bytes, 0, Transfers::CHUNK));
    // The source can't read it (no import task of its own).
    Transfers::daemon($this->source)->send($this, 'GET', "/daemon/v1/transfers/{$transfer?->public_id}/chunks/0")->assertNotFound();

    VolumeServices::finish($this->target, $task, 'succeeded', ['dataImport' => ['mode' => 'import', 'empty' => false, 'tables' => 3, 'sizeBytes' => 1000, 'serverVersion' => '18.1', 'format' => 'postgres_custom', 'readBytes' => strlen($bytes), 'durationMs' => 500]]);
    resolve(AdvanceServerMoves::class)->handle();

    expect($dbItem->refresh()->status)->toBe(MoveItemStatus::Done)
        ->and($move->refresh()->status)->toBe(ServerMoveStatus::Ready)
        // The app starts again, now against the database on the target.
        ->and(collect(Projects::nodeState($this->target)['workloads'] ?? [])->pluck('id')->all())->toContain($webWorkload?->workload_id)
        ->and(AuditEvent::query()->where('action', AuditAction::ServerMoveItemFinished->value)->count())->toBe(2);

    // Retire: typed name, recent password; the target takes the source's place.
    $retire = route('servers.move.retire', [$this->source, $move->public_id]);
    $this->actingAs($this->owner)->withSession(passwordConfirmed())->post($retire, ['confirmation' => 'x'])->assertSessionHasErrors('confirmation');
    $this->actingAs($this->owner)->withSession(passwordConfirmed())->post($retire, ['confirmation' => 'droplet'])->assertRedirect();

    expect($move->refresh()->status)->toBe(ServerMoveStatus::Completed)
        ->and($this->source->refresh()->status)->toBe(ServerStatus::Revoked)
        ->and($this->source->replaced_by_server_id)->toBe($this->target->id)
        ->and($this->target->refresh()->roles->map(fn (ServerRole $role): string => $role->value)->sort()->values()->all())->toBe(['build', 'edge', 'runtime'])
        ->and($this->target->labels)->toBe(['zone' => 'ams3'])
        ->and(Projects::nodeState($this->source)['workloads'] ?? [])->toBe([])
        ->and(AuditEvent::query()->where('action', AuditAction::ServerMoveCompleted->value)->exists())->toBeTrue();
});

test('a failed copy after the cutover puts the database back on the source, and a retry starts over', function (): void {
    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('servers.move.store', $this->source), ['target' => $this->target->public_id, 'confirmation' => 'droplet'])
        ->assertRedirect();
    settleServers([$this->target, $this->source]);
    $webWorkload = workloadOn($this->web, $this->target);
    stopped($this->web);
    daemonExports($this, $this->source, Transfers::pgCustom('18.1', '18.1'));
    resolve(AdvanceServerMoves::class)->handle();
    settleServers([$this->target, $this->source], phases: exited($this->web));
    resolve(AdvanceImports::class)->handle();

    $task = VolumeServices::lease($this->target, DaemonTaskType::DbImport);
    VolumeServices::finish($this->target, $task, 'failed', ['error' => 'pg_restore failed: out of disk space']);
    resolve(AdvanceServerMoves::class)->handle();

    $item = ServerMoveItem::query()->where('kind', MoveItemKind::Dump->value)->sole();
    expect($item->status)->toBe(MoveItemStatus::RollingBack)
        ->and($item->failed_step)->toBe('importing')
        ->and(Volume::query()->where('service_instance_id', $this->postgres->id)->sole()->server_id)->toBe($this->source->id);

    settleServers([$this->source, $this->target]);
    expect($item->refresh()->status)->toBe(MoveItemStatus::Failed)
        ->and($item->message)->toContain('out of disk space')->toContain('runs on droplet again')
        ->and(workloadOn($this->postgres, $this->source))->not->toBeNull()
        ->and(workloadOn($this->postgres, $this->target))->toBeNull()
        // Its app is released (it runs against the database on the source again).
        ->and(collect(Projects::nodeState($this->target)['workloads'] ?? [])->pluck('id')->all())->toContain($webWorkload?->workload_id);

    $this->actingAs($this->owner)->post(route('servers.move.retry', [$this->source, ServerMove::query()->sole()->public_id, $item->public_id]))->assertRedirect();
    expect($item->refresh()->status)->toBe(MoveItemStatus::Stopping)
        ->and($item->attempts)->toBe(2)
        ->and(AuditEvent::query()->where('action', AuditAction::ServerMoveItemRetried->value)->exists())->toBeTrue();
});

test('cancelling before the copy keeps the database on the source; moved apps stay moved', function (): void {
    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('servers.move.store', $this->source), ['target' => $this->target->public_id, 'confirmation' => 'droplet'])
        ->assertRedirect();
    settleServers([$this->target, $this->source]);
    $move = ServerMove::query()->sole();

    $this->actingAs($this->owner)->post(route('servers.move.cancel', [$this->source, $move->public_id]))->assertRedirect();

    expect($move->refresh()->status)->toBe(ServerMoveStatus::Cancelled)
        ->and(ServerMoveItem::query()->where('kind', MoveItemKind::Stateless->value)->sole()->status)->toBe(MoveItemStatus::Done)
        ->and(ServerMoveItem::query()->where('kind', MoveItemKind::Dump->value)->sole()->status)->toBe(MoveItemStatus::Cancelled)
        ->and(Volume::query()->where('service_instance_id', $this->postgres->id)->sole()->server_id)->toBe($this->source->id)
        ->and(AuditEvent::query()->where('action', AuditAction::ServerMoveCancelled->value)->count())->toBe(2);
    // Nothing is held any more, and the source takes new services again.
    expect(collect(Projects::nodeState($this->target)['workloads'] ?? [])->pluck('id')->all())->toContain(workloadOn($this->web, $this->target)?->workload_id);
});

test('a service with files is held while its volume is created on the target and copied in', function (): void {
    // Deployed on the source only.
    $this->target->forceFill(['roles' => [ServerRole::Build->value]])->save();
    [, , , $app] = VolumeServices::runningApp($this->team, $this->source, 'unused');
    $this->target->forceFill(['roles' => [ServerRole::Runtime->value, ServerRole::Build->value]])->save();
    $this->target->refresh();
    $plan = resolve(MovePlanner::class)->plan($this->source, $this->target);
    $item = collect($plan->items)->firstWhere('instanceId', $app->public_id);
    expect($item?->kind)->toBe(MoveItemKind::Files);

    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('servers.move.store', $this->source), ['target' => $this->target->public_id, 'confirmation' => 'droplet'])
        ->assertRedirect();

    // Simulate its turn: the database moved already.
    $files = ServerMoveItem::query()->where('service_instance_id', $app->id)->sole();
    ServerMoveItem::query()->where('kind', '!=', MoveItemKind::Files->value)->get()->each(function (ServerMoveItem $other): void {
        $other->forceFill(['status' => MoveItemStatus::Skipped->value])->saveQuietly();
    });
    resolve(AdvanceServerMoves::class)->handle();
    expect($files->refresh()->status)->toBe(MoveItemStatus::Stopping)
        ->and(collect(Projects::nodeState($this->source)['workloads'] ?? [])->pluck('id')->all())->not->toContain(workloadOn($app, $this->source)?->workload_id);

    stopped($app);
    expect($files->refresh()->status)->toBe(MoveItemStatus::Exporting);

    $task = VolumeServices::lease($this->source, DaemonTaskType::VolumeExport);
    $spec = json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    $volume = Volume::query()->where('service_instance_id', $app->id)->sole();
    expect($spec['volume'])->toBe($volume->public_id);
    $tar = str_repeat('t', 4096);
    Transfers::daemon($this->source)->send($this, 'PUT', "/daemon/v1/transfers/{$spec['transfer']['id']}/chunks/0", $tar, ['Content-Type' => 'application/octet-stream'])->assertNoContent();
    VolumeServices::finish($this->source, $task, 'succeeded', ['dataExport' => ['format' => 'tar', 'compressed' => true, 'sizeBytes' => 4096, 'chunks' => 1, 'sha256' => Transfers::digest($tar), 'durationMs' => 10]]);
    resolve(AdvanceServerMoves::class)->handle();

    // The volume is declared on the target before the service runs there.
    expect($files->refresh()->status)->toBe(MoveItemStatus::Preparing)
        ->and(collect(Projects::nodeState($this->target)['volumes'] ?? [])->pluck('id')->all())->toContain($volume->public_id)
        ->and(workloadOn($app, $this->target))->toBeNull();
});

test('the source drains: new deployments go to the target while the move is open', function (): void {
    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('servers.move.store', $this->source), ['target' => $this->target->public_id, 'confirmation' => 'droplet'])
        ->assertRedirect();

    expect(workloadOn($this->web, $this->target))->not->toBeNull();
    // Another move of the same source is refused meanwhile.
    expect(implode(' ', resolve(MovePlanner::class)->plan($this->source, $this->target)->blockers))->toContain('already part of a move');
});

test('an architecture change rebuilds apps built from source for the target, and needs a builder for it', function (): void {
    $planner = resolve(MovePlanner::class);
    $this->source->forceFill(['facts' => new HostFactsData('host', 'linux', 'amd64', 4, 8192)])->save();
    $app = $this->web->service;
    $app->forceFill(['kind' => ServiceKind::App])->saveQuietly();
    Deployment::query()->whereKey($this->web->refresh()->active_deployment_id)->update(['plan' => json_encode(['platform' => 'linux/amd64'])]);

    $plan = $planner->plan($this->source->refresh(), $this->target);
    expect($plan->platformFrom)->toBe('linux/amd64')
        ->and($plan->platformTo)->toBe('linux/arm64')
        ->and(collect($plan->items)->firstWhere('instanceId', $this->web->public_id)?->rebuild)->toBeTrue()
        ->and($plan->blockers)->toBe([]);

    // Without a server that builds arm64, the move is refused up front.
    $this->target->forceFill(['roles' => [ServerRole::Runtime->value]])->save();
    $this->source->forceFill(['roles' => [ServerRole::Edge->value, ServerRole::Runtime->value]])->save();
    expect(implode(' ', $planner->plan($this->source->refresh(), $this->target->refresh())->blockers))->toContain('No build server builds linux/arm64');

    // A build for the target's platform ends without rolling out.
    $deployment = Deployment::query()->whereKey($this->web->active_deployment_id)->sole();
    $deployment->plan = [Prebuilds::KEY => 'linux/arm64'];
    expect(Prebuilds::platform($deployment))->toBe('linux/arm64');
    $deployment->plan = [Prebuilds::KEY => 'linux/s390x'];
    expect(Prebuilds::platform($deployment))->toBeNull();
});
