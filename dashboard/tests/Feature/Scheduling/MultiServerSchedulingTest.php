<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Servers\MarkServerSeen;
use App\Actions\Servers\MarkStaleServers;
use App\Actions\Servers\RescheduleDeadServers;
use App\Actions\Services\UpdateServiceSettings;
use App\Actions\Variables\CreateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Services\ScalingSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Enums\AuditAction;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\PlacementStrategy;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\VariableType;
use App\Enums\WorkloadRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Placement;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Queries\CanvasGraph;
use Illuminate\Contracts\Database\Query\Builder;
use Tests\Support\Projects;

/*
| Multi-server scheduling (docs/02 §2, §6, docs/04 §8): placement
| constraints, rolling updates across servers, rescheduling off dead
| servers, stateful pinning and the degraded mark.
*/

beforeEach(fn () => Projects::useTestKek());

/**
 * Two servers: `one` (edge, runtime, build) and `two` (runtime).
 *
 * @return array{Team, Server, Server, Project, Environment}
 */
function twoServers(): array
{
    $team = Team::factory()->create();
    [$one] = Projects::server($team);
    [$two] = Projects::server($team, [ServerRole::Runtime], ip: '203.0.113.8');
    $one->forceFill(['name' => 'hel-1'])->save();
    $two->forceFill(['name' => 'fra-2'])->save();
    $project = Projects::create($team, 'shop');

    return [$team, $one->refresh(), $two->refresh(), $project, $project->production()->sole()];
}

function scale(Service $service, ScalingSettings $scaling): ServiceInstance
{
    $instance = ServiceInstance::query()->where('service_id', $service->id)->sole();

    return resolve(UpdateServiceSettings::class)->handle($instance, UpdateServiceSettingsData::from(['scaling' => $scaling]));
}

function deployAll(Environment $environment): void
{
    resolve(DeployEnvironment::class)->handle($environment, null, AuditActor::system());
}

function latestOf(Service $service): Deployment
{
    return Deployment::query()->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $service->id))->latest('id')->firstOrFail();
}

/**
 * @param  list<Server>  $servers
 */
function reportAll(array $servers, int $rounds = 1): void
{
    for ($i = 0; $i < $rounds; $i++) {
        foreach ($servers as $server) {
            Projects::report($server);
        }
    }
}

test('replicas spread across servers; the canvas shows the count and the servers', function (): void {
    [, $one, $two, $project, $production] = twoServers();
    $web = Projects::image($project, 'web');
    scale($web, new ScalingSettings(replicas: 3));
    deployAll($production);
    reportAll([$one, $two], 3);

    $deployment = latestOf($web);
    $servers = Placement::query()->where('deployment_id', $deployment->id)->orderBy('slot')->pluck('server_id')->all();

    expect($deployment->status)->toBe(DeploymentStatus::Active)
        ->and($servers)->toBe([$one->id, $two->id, $one->id])
        ->and(collect(Projects::nodeState($two)['workloads'])->where('service', $web->public_id))->toHaveCount(1);

    $node = collect(resolve(CanvasGraph::class)->for($production)['nodes'])->firstWhere('id', $web->public_id);

    expect($node->replicas)->toBe(3)
        ->and($node->server)->toBe('hel-1, fra-2');
});

test('placement constraints: labels, allow/deny lists, pack and the production flag', function (): void {
    [, $one, $two, $project, $production] = twoServers();
    $two->forceFill(['labels' => ['disk' => 'ssd']])->save();
    $web = Projects::image($project, 'web');

    scale($web, new ScalingSettings(replicas: 2, placementLabels: ['disk' => 'ssd']));
    deployAll($production);
    expect(Placement::query()->pluck('server_id')->unique()->values()->all())->toBe([$two->id]);
    reportAll([$one, $two], 3);

    scale($web, new ScalingSettings(replicas: 2, denyServers: [$two->public_id]));
    deployAll($production);
    reportAll([$one, $two], 6);
    expect(Placement::query()->where('deployment_id', latestOf($web)->id)->pluck('server_id')->unique()->values()->all())->toBe([$one->id]);

    // "Allow production" off: production replicas avoid the server.
    $one->forceFill(['allow_production' => false])->save();
    scale($web, new ScalingSettings(replicas: 2, strategy: PlacementStrategy::Pack));
    deployAll($production);
    reportAll([$one, $two], 6);
    expect(Placement::query()->where('deployment_id', latestOf($web)->id)->pluck('server_id')->unique()->values()->all())->toBe([$two->id]);
});

test('no server satisfying the constraints fails the deployment with a placement error naming why', function (): void {
    [, $one, $two, $project, $production] = twoServers();
    $two->forceFill(['labels' => ['gpu' => 'yes']])->save();
    $web = Projects::image($project, 'web');
    scale($web, new ScalingSettings(placementLabels: ['gpu' => 'yes']));

    // The label goes away after the constraint was saved.
    $two->forceFill(['labels' => []])->save();
    deployAll($production);

    expect(latestOf($web)->status)->toBe(DeploymentStatus::Failed)
        ->and(latestOf($web)->failure_class)->toBe(DeploymentFailureClass::PlacementError)
        ->and(latestOf($web)->failure_message)->toContain('hel-1: missing label gpu=yes; fra-2: missing label gpu=yes');
});

test('constraints are validated when saved', function (): void {
    [$team, $one, , $project] = twoServers();
    $web = Projects::image($project, 'web');
    $foreign = Server::factory()->online()->create();

    expect(fn (): ServiceInstance => scale($web, new ScalingSettings(placementLabels: ['Bad Key' => 'x'])))->toThrow(DomainRuleViolation::class, 'not a label name')
        ->and(fn (): ServiceInstance => scale($web, new ScalingSettings(allowServers: [$foreign->public_id])))->toThrow(DomainRuleViolation::class, 'Unknown server')
        ->and(fn (): ServiceInstance => scale($web, new ScalingSettings(allowServers: [$one->public_id], denyServers: [$one->public_id])))->toThrow(DomainRuleViolation::class, 'both allowed and excluded')
        ->and(fn (): ServiceInstance => scale($web, new ScalingSettings(placementLabels: ['region' => 'eu'])))->toThrow(DomainRuleViolation::class, 'hel-1: missing label region=eu');

    // Replica changes never fail because of the servers.
    $one->forceFill(['labels' => ['region' => 'eu']])->save();
    scale($web, new ScalingSettings(placementLabels: ['region' => 'eu']));
    $one->forceFill(['labels' => []])->save();

    expect(scale($web, new ScalingSettings(replicas: 2, placementLabels: ['region' => 'eu']))->scaling->replicas)->toBe(2);
});

test('a rolling update replaces replicas one at a time across servers: max-surge 1, max-unavailable 0', function (): void {
    [, $one, $two, $project, $production] = twoServers();
    $web = Projects::image($project, 'web');
    scale($web, new ScalingSettings(replicas: 3));
    deployAll($production);
    reportAll([$one, $two], 3);
    $first = latestOf($web);
    expect($first->status)->toBe(DeploymentStatus::Active);

    resolve(CreateVariable::class)->handle($project, $web, VariableInput::from([
        'key' => 'GREETING', 'value' => 'hi', 'type' => VariableType::Config, 'targets' => [VariableTargetData::environment($production->public_id)],
    ]), null, AuditActor::system());
    deployAll($production);
    $second = latestOf($web);

    expect($second->id)->not->toBe($first->id)
        ->and(Placement::query()->where('deployment_id', $second->id)->count())->toBe(1);

    $snapshots = [];

    for ($round = 0; $round < 12 && $second->refresh()->status !== DeploymentStatus::Active; $round++) {
        reportAll([$one, $two]);

        $old = Placement::query()->where('deployment_id', $first->id)->get();
        $new = Placement::query()->where('deployment_id', $second->id)->get();
        $servingOld = $old->where('role', WorkloadRole::Serving)->count();
        $healthyNew = $new->filter(fn (Placement $p): bool => $p->phase->value === 'healthy')->count();
        $snapshots[] = [$servingOld, $new->count()];

        // Never fewer than 3 serving, never more than one extra starting.
        expect($servingOld + $healthyNew)->toBeGreaterThanOrEqual(3)
            ->and($servingOld + $new->count())->toBeLessThanOrEqual(4);
    }

    $new = Placement::query()->where('deployment_id', $second->id)->orderBy('slot')->get();

    expect($second->status)->toBe(DeploymentStatus::Active)
        ->and($new)->toHaveCount(3)
        ->and($new->pluck('server_id')->unique()->count())->toBe(2)
        ->and(Placement::query()->where('deployment_id', $first->id)->where('role', WorkloadRole::Serving->value)->count())->toBe(0)
        // It went through intermediate states with old and new serving side by side.
        ->and(collect($snapshots)->contains(fn (array $s): bool => $s[0] > 0 && $s[0] < 3))->toBeTrue()
        ->and($first->refresh()->status)->toBe(DeploymentStatus::Superseded);
});

test('healthy new replicas of a rolling update receive traffic next to the old ones', function (): void {
    [, $one, $two, $project, $production] = twoServers();
    $web = Projects::image($project, 'web');
    scale($web, new ScalingSettings(replicas: 2));
    deployAll($production);
    reportAll([$one, $two], 3);
    $first = latestOf($web);

    scale($web, new ScalingSettings(replicas: 2, memoryMb: 640));
    deployAll($production);
    $second = latestOf($web);
    $newOne = Placement::query()->where('deployment_id', $second->id)->sole();
    // The first new replica lands on the edge server (lowest id, previous servers tie).
    expect($newOne->server_id)->toBe($one->id);
    Projects::report($one);

    $route = collect(Projects::domainRoutes($one))->first(fn (array $route): bool => str_starts_with($route['hosts'][0], 'web-production'));
    $upstreams = collect($route['upstreams'])->pluck('workload')->filter()->values()->all();

    expect($upstreams)->toContain($newOne->workload_id)
        ->and($second->refresh()->status)->toBe(DeploymentStatus::HealthChecking)
        ->and(Placement::query()->where('deployment_id', $first->id)->where('role', WorkloadRole::Draining->value)->count())->toBe(1)
        ->and(Placement::query()->where('deployment_id', $second->id)->count())->toBe(2);
});

test('a failing replica mid-roll removes the new ones and puts the drained old ones back to serving', function (): void {
    [, $one, $two, $project, $production] = twoServers();
    $web = Projects::image($project, 'web');
    scale($web, new ScalingSettings(replicas: 2));
    deployAll($production);
    reportAll([$one, $two], 3);
    $first = latestOf($web);

    scale($web, new ScalingSettings(replicas: 2, cpus: 0.5));
    deployAll($production);
    $second = latestOf($web);
    Projects::report(Server::query()->findOrFail(Placement::query()->where('deployment_id', $second->id)->sole()->server_id));

    $pending = Placement::query()->where('deployment_id', $second->id)->where('phase', 'pending')->sole();
    $server = Server::query()->findOrFail($pending->server_id);
    Projects::report($server, phases: [$pending->workload_id => 'failed'], messages: [$pending->workload_id => 'exit code 1']);

    expect($second->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and(Placement::query()->where('deployment_id', $second->id)->count())->toBe(0)
        ->and(Placement::query()->where('deployment_id', $first->id)->where('role', WorkloadRole::Serving->value)->count())->toBe(2)
        ->and(Placement::query()->where('deployment_id', $first->id)->whereNotNull('drain_until')->count())->toBe(0);
});

/**
 * Lets the liveness watch run long enough, then marks `two` dead.
 */
function killServer(Server $dead, Server ...$alive): void
{
    resolve(RescheduleDeadServers::class)->handle();
    test()->travel(100)->seconds();

    foreach ($alive as $server) {
        resolve(MarkServerSeen::class)->handle($server->refresh());
    }

    resolve(MarkStaleServers::class)->handle();
    expect($dead->refresh()->status)->toBe(ServerStatus::Unreachable);
}

test('stateless replicas of a dead server move to healthy servers; its NodeState drops them', function (): void {
    [, $one, $two, $project, $production] = twoServers();
    $web = Projects::image($project, 'web');
    scale($web, new ScalingSettings(replicas: 2));
    deployAll($production);
    reportAll([$one, $two], 3);
    $deployment = latestOf($web);
    $moved = Placement::query()->where('server_id', $two->id)->sole();

    killServer($two, $one);

    expect(resolve(RescheduleDeadServers::class)->handle())->toBe(1);

    $placements = Placement::query()->where('deployment_id', $deployment->id)->orderBy('slot')->get();

    expect($placements->pluck('server_id')->all())->toBe([$one->id, $one->id])
        ->and($placements->pluck('slot')->all())->toBe([0, 1])
        ->and(Placement::query()->where('workload_id', $moved->workload_id)->exists())->toBeFalse()
        ->and(collect(Projects::nodeState($two)['workloads'])->pluck('id')->all())->not->toContain($moved->workload_id)
        ->and(collect(Projects::nodeState($one)['workloads'])->where('service', $web->public_id))->toHaveCount(2)
        ->and(AuditEvent::query()->where('action', AuditAction::ReplicasRescheduled->value)->where('subject_id', $two->public_id)->exists())->toBeTrue();

    // Idempotent.
    expect(resolve(RescheduleDeadServers::class)->handle())->toBe(0);
});

test('nothing moves before the threshold or while the dashboard just started watching', function (): void {
    [, $one, $two, $project, $production] = twoServers();
    $web = Projects::image($project, 'web');
    scale($web, new ScalingSettings(replicas: 2));
    deployAll($production);
    reportAll([$one, $two], 3);

    // The watch starts now: a server unseen for long is not moved yet.
    $two->forceFill(['last_seen_at' => now()->subHour(), 'status' => ServerStatus::Unreachable])->save();
    expect(resolve(RescheduleDeadServers::class)->handle())->toBe(0);

    // Unseen for 60 s only: still not dead.
    test()->travel(100)->seconds();
    resolve(MarkServerSeen::class)->handle($one->refresh());
    $two->forceFill(['last_seen_at' => now()->subSeconds(60)])->save();
    expect(resolve(RescheduleDeadServers::class)->handle())->toBe(0)
        ->and(Placement::query()->where('server_id', $two->id)->count())->toBe(1);
});

test('stateful services are never moved: degraded until their server returns', function (): void {
    [, $one, $two, $project, $production] = twoServers();
    $postgres = Projects::postgres($project);
    scale($postgres, new ScalingSettings(allowServers: [$two->public_id]));
    deployAll($production);
    reportAll([$one, $two], 3);
    $placement = Placement::query()->sole();
    expect($placement->server_id)->toBe($two->id);

    killServer($two, $one);
    resolve(RescheduleDeadServers::class)->handle();
    resolve(RescheduleDeadServers::class)->handle();

    $instance = ServiceInstance::query()->where('service_id', $postgres->id)->sole();
    $node = collect(resolve(CanvasGraph::class)->for($production)['nodes'])->firstWhere('id', $postgres->public_id);

    expect(Placement::query()->sole()->workload_id)->toBe($placement->workload_id)
        ->and($instance->placement_issue['kind'])->toBe('stateful_server_down')
        ->and($instance->placement_issue['serverId'])->toBe($two->public_id)
        ->and($node->status->label)->toBe('Server down')
        ->and($node->placementIssue?->kind)->toBe('stateful_server_down')
        ->and(AuditEvent::query()->where('action', AuditAction::ServiceDegraded->value)->count())->toBe(1);

    resolve(MarkServerSeen::class)->handle($two->refresh());
    resolve(RescheduleDeadServers::class)->handle();

    expect($instance->refresh()->placement_issue)->toBeNull();
});

test('replicas with nowhere to go stay and mark the service degraded', function (): void {
    [, $one, $two, $project, $production] = twoServers();
    $web = Projects::image($project, 'web');
    scale($web, new ScalingSettings(replicas: 1, allowServers: [$two->public_id]));
    deployAll($production);
    reportAll([$one, $two], 3);

    killServer($two, $one);
    expect(resolve(RescheduleDeadServers::class)->handle())->toBe(0);

    $instance = ServiceInstance::query()->where('service_id', $web->id)->sole();

    expect(Placement::query()->sole()->server_id)->toBe($two->id)
        ->and($instance->placement_issue['kind'])->toBe('unplaceable')
        ->and($instance->placement_issue['message'])->toContain('hel-1: not in the allowed servers');
});

test('scaling up and down is a runtime-only change: no build, the image is reused', function (): void {
    [, $one, $two, $project, $production] = twoServers();
    $web = Projects::image($project, 'web');
    deployAll($production);
    reportAll([$one, $two], 3);
    $first = latestOf($web);

    scale($web, new ScalingSettings(replicas: 3));
    deployAll($production);
    reportAll([$one, $two], 8);

    expect(latestOf($web)->status)->toBe(DeploymentStatus::Active)
        ->and(latestOf($web)->image)->toBe($first->image)
        ->and(Placement::query()->where('deployment_id', latestOf($web)->id)->count())->toBe(3);

    scale($web, new ScalingSettings(replicas: 1));
    deployAll($production);
    reportAll([$one, $two], 8);

    expect(latestOf($web)->status)->toBe(DeploymentStatus::Active)
        ->and(Placement::query()->where('deployment_id', latestOf($web)->id)->count())->toBe(1);
});
