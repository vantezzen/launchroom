<?php

declare(strict_types=1);

use App\Actions\Services\StopService;
use App\Data\Audit\AuditActor;
use App\Data\Canvas\EnvironmentLiveStatusData;
use App\Data\Canvas\NodeStatusData;
use App\Enums\DeploymentStatus;
use App\Enums\HoldKind;
use App\Enums\NodeStatusReason;
use App\Enums\ServerStatus;
use App\Enums\ServiceRemedy;
use App\Enums\StatusTone;
use App\Enums\TeamRole;
use App\Events\Broadcasts\ServiceStatusChanged;
use App\Events\ServerStatusChanged;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Queries\CanvasGraph;
use App\Queries\CommandPalette;
use App\Queries\EnvironmentLiveStatus;
use App\Queries\ProjectSummaries;
use App\Services\Holds\Holds;
use App\Support\Canvas\NodeStatus;
use App\Support\PublicId;
use Illuminate\Support\Facades\Event;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/*
| D108, docs/08 §23: one derivation of a service's status (NodeStatus via
| ServiceStatusIndex), the same on every surface, explained (`cause`) and
| fixable (`remedy`), and a `service.status` nudge on every transition that
| doesn't change the canvas.
*/

beforeEach(fn () => Projects::useTestKek());

function statusInstance(DeploymentUi $world, ?Service $service = null): ServiceInstance
{
    return ServiceInstance::query()->where('environment_id', $world->production->id)->where('service_id', ($service ?? $world->web)->id)->sole();
}

function liveEntry(DeploymentUi $world, ?Service $service = null): NodeStatusData
{
    $snapshot = resolve(EnvironmentLiveStatus::class)->for($world->production);
    $id = ($service ?? $world->web)->public_id;

    foreach ($snapshot->services as $entry) {
        if ($entry->serviceId === $id) {
            return $entry->status;
        }
    }

    throw new RuntimeException("{$id} is not in the live status.");
}

/**
 * The web service's status as the canvas node, the live-status store, ⌘K
 * and the project card show it.
 *
 * @return array{canvas: NodeStatusData, live: NodeStatusData, palette: NodeStatusData|null, card: NodeStatusData}
 */
function everySurface(DeploymentUi $world): array
{
    $canvas = collect(resolve(CanvasGraph::class)->for($world->production)['nodes'])->firstWhere('id', $world->web->public_id);
    $viewer = $world->member(TeamRole::Viewer);
    $palette = collect(resolve(CommandPalette::class)->for($world->team, $viewer, $world->production->public_id)->services)->firstWhere('id', $world->web->public_id);
    $summary = collect(resolve(ProjectSummaries::class)->for($world->team))->firstWhere('id', $world->project->public_id);
    $production = collect($summary->environments)->firstWhere('id', $world->production->public_id);

    return [
        'canvas' => $canvas->status,
        'live' => liveEntry($world),
        'palette' => $palette?->nodeStatus,
        // A single-service problem: the environment's problem names web.
        'card' => collect($production->problems)->firstWhere('serviceId', $world->web->public_id)->status ?? $production->status,
    ];
}

test('a live service is Live on every surface, with nothing to fix', function (): void {
    $world = DeploymentUi::make();
    $status = liveEntry($world);

    expect($status->reason)->toBe(NodeStatusReason::Live)
        ->and($status->cause)->toBeNull()
        ->and($status->remedy)->toBeNull()
        ->and(everySurface($world)['canvas']->reason)->toBe(NodeStatusReason::Live);
});

test('a container that exited cleanly is Not running: exited, with Start', function (): void {
    $world = DeploymentUi::make();
    $placement = Placement::query()->where('deployment_id', statusInstance($world)->active_deployment_id)->sole();
    $exitedAt = now()->subHours(2)->utc();

    Projects::report($world->server, phases: [$placement->workload_id => 'exited'], events: [[
        'workload' => $placement->workload_id,
        'kind' => 'exited',
        'at' => $exitedAt->format('Y-m-d\TH:i:s\Z'),
        'exitCode' => 0,
        'seq' => 1,
    ]]);

    $status = liveEntry($world);
    $surfaces = everySurface($world);

    expect($status->reason)->toBe(NodeStatusReason::NotRunning)
        ->and($status->state)->toBe(StatusTone::Warning)
        ->and($status->cause)->toBe('exited')
        ->and($status->remedy)->toBe(ServiceRemedy::StartContainers)
        ->and($status->since?->getTimestamp())->toBe($exitedAt->getTimestamp())
        ->and($surfaces['canvas']->reason)->toBe(NodeStatusReason::NotRunning)
        ->and($surfaces['palette']?->reason)->toBe(NodeStatusReason::NotRunning)
        ->and($surfaces['card']->reason)->toBe(NodeStatusReason::NotRunning);
});

test('a container that ran out of memory crashed, with Restart', function (): void {
    $world = DeploymentUi::make();
    $placement = Placement::query()->where('deployment_id', statusInstance($world)->active_deployment_id)->sole();

    Projects::report($world->server, phases: [$placement->workload_id => 'exited'], events: [[
        'workload' => $placement->workload_id,
        'kind' => 'exited',
        'at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'exitCode' => 137,
        'oomKilled' => true,
        'seq' => 1,
    ]]);

    expect(liveEntry($world))
        ->reason->toBe(NodeStatusReason::Crashed)
        ->cause->toBe('ran out of memory')
        ->remedy->toBe(ServiceRemedy::Restart);
});

test('a service on a server that stopped reporting says so everywhere, and offline is down', function (): void {
    $world = DeploymentUi::make();
    Server::query()->whereKey($world->server->id)->update(['status' => ServerStatus::Unreachable->value]);
    $name = $world->server->name;

    $surfaces = everySurface($world);
    expect($surfaces['live'])
        ->reason->toBe(NodeStatusReason::ServerUnreachable)
        ->state->toBe(StatusTone::Warning)
        ->cause->toBe("{$name} isn’t reporting")
        ->and($surfaces['canvas']->reason)->toBe(NodeStatusReason::ServerUnreachable)
        ->and($surfaces['palette']?->reason)->toBe(NodeStatusReason::ServerUnreachable)
        ->and($surfaces['card']->reason)->toBe(NodeStatusReason::ServerUnreachable);

    Server::query()->whereKey($world->server->id)->update(['status' => ServerStatus::Offline->value]);

    expect(liveEntry($world))
        ->reason->toBe(NodeStatusReason::ServerDown)
        ->cause->toBe("{$name} is offline");
});

test('a stopped service offers Start and a failed deploy offers Retry deploy', function (): void {
    $world = DeploymentUi::make();
    $owner = $world->member(TeamRole::Owner);
    resolve(StopService::class)->handle(statusInstance($world), false, $owner, AuditActor::user($owner));

    expect(liveEntry($world))
        ->reason->toBe(NodeStatusReason::Stopped)
        ->remedy->toBe(ServiceRemedy::Start);

    // ⌘K's live deployment of the stopped service says Stopped, not Live.
    $palette = resolve(CommandPalette::class)->for($world->team, $owner, $world->production->public_id);
    $live = collect($palette->deployments)->firstWhere('id', statusInstance($world)->activeDeployment?->public_id);
    expect($live?->nodeStatus?->reason)->toBe(NodeStatusReason::Stopped);

    // Stopped on purpose is never "Not deployed".
    expect(NodeStatus::aggregate([liveEntry($world)]))
        ->reason->toBe(NodeStatusReason::Stopped)
        ->label->toBe('Stopped');

    $world = DeploymentUi::make();
    $failed = $world->latest($world->web)->replicate();
    $failed->forceFill(['public_id' => PublicId::generate(Deployment::publicIdPrefix()), 'status' => DeploymentStatus::Failed, 'finished_at' => now()])->save();

    expect(liveEntry($world))
        ->reason->toBe(NodeStatusReason::LastDeployFailed)
        ->remedy->toBe(ServiceRemedy::RetryDeploy);
});

test('the live status is one snapshot with a version, its health and the problems with their links', function (): void {
    $world = DeploymentUi::make();
    Server::query()->whereKey($world->server->id)->update(['status' => ServerStatus::Offline->value]);

    $before = (int) floor(microtime(true) * 1_000_000);
    $snapshot = resolve(EnvironmentLiveStatus::class)->for($world->production);
    $web = collect($snapshot->services)->firstWhere('serviceId', $world->web->public_id);

    expect($snapshot)->toBeInstanceOf(EnvironmentLiveStatusData::class)
        ->and($snapshot->version)->toBeGreaterThanOrEqual($before)
        ->and($snapshot->health->status->state)->toBe(StatusTone::Failed)
        ->and($web->problem?->link)->toBe('Open servers')
        ->and($web->problem?->href)->toBe(route('servers.index', absolute: false));
});

test('members read an environment\'s live status; other teams never do', function (): void {
    $world = DeploymentUi::make();
    $route = route('projects.live-status', $world->envArgs());

    $this->actingAs($world->member(TeamRole::Viewer))
        ->getJson($route)
        ->assertOk()
        ->assertJsonPath('environmentId', $world->production->public_id)
        ->assertJsonPath('services.0.status.reason', fn (string $reason): bool => NodeStatusReason::tryFrom($reason) !== null)
        ->assertJsonStructure(['version', 'health' => ['status', 'deploying', 'deployFailed'], 'services' => [['serviceId', 'name', 'logo', 'status' => ['cause', 'remedy'], 'problem']]]);

    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($outsider)->getJson($route)->assertNotFound();

    auth()->logout();
    $this->getJson($route)->assertUnauthorized();
});

test('a workload phase change nudges its environment once; an unchanged report does not', function (): void {
    $world = DeploymentUi::make();
    $placement = Placement::query()->where('deployment_id', statusInstance($world)->active_deployment_id)->sole();
    Event::fake([ServiceStatusChanged::class]);

    Projects::report($world->server);
    Event::assertNotDispatched(ServiceStatusChanged::class);

    Projects::report($world->server, phases: [$placement->workload_id => 'unhealthy']);
    Event::assertDispatched(ServiceStatusChanged::class, static fn (ServiceStatusChanged $event): bool => $event->environmentId === $world->production->public_id
        && $event->serviceIds === [$world->web->public_id]);
    Event::assertDispatchedTimes(ServiceStatusChanged::class, 1);

    expect($placement->refresh()->phase_changed_at)->not->toBeNull();
});

test('a server status change nudges the services live on it', function (): void {
    $world = DeploymentUi::make();
    Event::fake([ServiceStatusChanged::class]);

    event(new ServerStatusChanged($world->server->id, ServerStatus::Online, ServerStatus::Unreachable));

    Event::assertDispatched(ServiceStatusChanged::class, static fn (ServiceStatusChanged $event): bool => $event->environmentId === $world->production->public_id
        && in_array($world->web->public_id, $event->serviceIds, true)
        && in_array($world->postgres->public_id, $event->serviceIds, true));
});

test('a hold starting nudges its services', function (): void {
    $world = DeploymentUi::make();
    Event::fake([ServiceStatusChanged::class]);

    Holds::changed(HoldKind::Restore, [statusInstance($world)->id]);

    Event::assertDispatched(ServiceStatusChanged::class, static fn (ServiceStatusChanged $event): bool => $event->serviceIds === [$world->web->public_id]);
});
