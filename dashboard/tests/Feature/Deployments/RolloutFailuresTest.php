<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Deployments\SweepDeployments;
use App\Data\Audit\AuditActor;
use App\Data\Deployments\LogLineData;
use App\Enums\CanvasNodeState;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentStepName;
use App\Enums\WorkloadPhase;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Queries\DeploymentDetailView;
use App\Queries\DeploymentLogs;
use App\Support\Canvas\NodeStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Tests\Support\Projects;

/*
 * Rollouts never hang silently (D68, docs/04 §8.1): launchroomd's issues
 * fail a rollout right away, stuck downloads and refused desired states
 * fail with the sub-step named, the running steps say what they wait for,
 * and container lifecycle events land in the deployment's log.
 */

beforeEach(fn () => Projects::useTestKek());
afterEach(fn () => Date::setTestNow());

/**
 * @return array{0: Server, 1: Service, 2: Placement}
 */
function rollingOut(): array
{
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $project = Projects::create($team, 'shop');
    $web = Projects::image($project, 'web', port: 8000);
    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());

    return [$server, $web, latestOf($web)->placements()->sole()];
}

function latestOf(Service $service): Deployment
{
    return Deployment::query()->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $service->id))->latest('id')->firstOrFail();
}

function stepMessage(Deployment $deployment, DeploymentStepName $name): ?string
{
    foreach (resolve(DeploymentDetailView::class)->get($deployment)->steps as $step) {
        if ($step->name === $name) {
            return $step->message;
        }
    }

    return null;
}

function stamp(?CarbonInterface $at = null): string
{
    return ($at ?? now())->utc()->format('Y-m-d\TH:i:s\Z');
}

test('a crash loop fails the rollout at once, with the last output', function (): void {
    [$server, $web, $placement] = rollingOut();
    $w = $placement->workload_id;
    Projects::report($server, 'starting', events: [[
        'seq' => 10, 'workload' => $w, 'at' => stamp(), 'kind' => 'exited', 'exitCode' => 1,
        'output' => [['time' => stamp(), 'stream' => 'stderr', 'text' => 'RuntimeError: FIXTURE_MODE=crash: the app fails while importing']],
    ]]);
    expect(latestOf($web)->status)->toBe(DeploymentStatus::HealthChecking);

    Projects::report($server, 'unhealthy', fields: [$w => ['restartCount' => 3, 'issue' => ['kind' => 'crash_loop', 'since' => stamp(), 'exitCode' => 1, 'restarts' => 3]]]);

    $deployment = latestOf($web);
    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_class)->toBe(DeploymentFailureClass::RuntimeError)
        ->and($deployment->failure_message)->toContain('keeps crashing: it exited with code 1 and was restarted 3 times')
        ->and($deployment->failure_message)->toContain('RuntimeError: FIXTURE_MODE=crash');
});

test('an app listening on 127.0.0.1 fails as a port mismatch that says how to bind', function (): void {
    [$server, $web, $placement] = rollingOut();
    Projects::report($server, 'starting', fields: [$placement->workload_id => ['issue' => ['kind' => 'loopback_only', 'since' => stamp(), 'port' => 8000, 'listening' => [8000]]]]);

    $deployment = latestOf($web);
    expect($deployment->failure_class)->toBe(DeploymentFailureClass::PortMismatch)
        ->and($deployment->failure_message)->toContain('listens on 127.0.0.1:8000')
        ->and($deployment->failure_message)->toContain('0.0.0.0:8000');
});

test('an app on another port names the port to set', function (): void {
    [$server, $web, $placement] = rollingOut();
    Projects::report($server, 'starting', fields: [$placement->workload_id => ['issue' => ['kind' => 'wrong_port', 'since' => stamp(), 'port' => 8000, 'listening' => [3000]]]]);

    expect(latestOf($web)->failure_message)->toContain('The app listens on 3000, not 8000')
        ->and(latestOf($web)->failure_message)->toContain('Set the service port to 3000');
});

test('the running steps say what they wait for', function (): void {
    [$server, $web, $placement] = rollingOut();
    $w = $placement->workload_id;

    // The server hasn't applied the rendered state yet.
    $server->refresh()->forceFill(['observed_version' => 0])->save();
    expect(stepMessage(latestOf($web), DeploymentStepName::Rollout))->toBe("Waiting for {$server->name} to pick up the change");

    Projects::report($server, 'starting', fields: [$w => ['health' => ['state' => 'starting', 'consecutiveFailures' => 0, 'lastError' => 'connection refused', 'attempts' => 3]]]);
    expect(stepMessage(latestOf($web), DeploymentStepName::Health))->toBe('Waiting for GET / on :8000 to answer (attempt 3: connection refused)');
});

test('a download that stops moving fails and names the path', function (): void {
    [$server, $web, $placement] = rollingOut();
    $stuck = stamp();
    Projects::report($server, 'pulling', pulls: [$placement->workload_id => ['stage' => 'downloading', 'downloadedBytes' => 100_000_000, 'totalBytes' => 412_000_000, 'updatedAt' => $stuck]]);

    Date::setTestNow(now()->addMinutes(3));
    Projects::report($server, 'pulling', pulls: [$placement->workload_id => ['stage' => 'downloading', 'downloadedBytes' => 100_000_000, 'totalBytes' => 412_000_000, 'updatedAt' => $stuck]]);
    resolve(SweepDeployments::class)->handle();

    $deployment = latestOf($web);
    expect($deployment->failure_class)->toBe(DeploymentFailureClass::Transient)
        ->and($deployment->failure_message)->toContain("{$server->name} couldn't download the image")
        ->and($deployment->failure_message)->toContain('no data for 2 minutes')
        ->and($deployment->failure_message)->toContain('100 MB of 412 MB');
});

test('a download that crawls ends at the Roll out timeout', function (): void {
    [$server, $web, $placement] = rollingOut();

    for ($minute = 1; $minute <= 11; $minute++) {
        Date::setTestNow(now()->addMinute());
        Projects::report($server, 'pulling', pulls: [$placement->workload_id => ['stage' => 'downloading', 'downloadedBytes' => $minute * 1_000_000, 'totalBytes' => 412_000_000, 'updatedAt' => stamp()]]);
        resolve(SweepDeployments::class)->handle();
    }

    expect(latestOf($web)->failure_message)->toContain('was still downloading the image')
        ->and(latestOf($web)->failure_message)->toContain('after 10 minutes');
});

test('a refused desired state fails the rollout with the server and its reason', function (): void {
    [$server, $web] = rollingOut();
    $server->refresh()->forceFill(['state_rejection' => ['version' => $server->desired_version, 'error' => 'decode: unknown field "stopped"', 'rejectedAt' => stamp()]])->save();

    resolve(SweepDeployments::class)->handle();

    expect(latestOf($web)->failure_class)->toBe(DeploymentFailureClass::ConfigError)
        ->and(latestOf($web)->failure_message)->toContain("{$server->name} refused the new desired state")
        ->and(latestOf($web)->failure_message)->toContain('unknown field');
});

test('lifecycle events become management lines in the deployment log, once', function (): void {
    [$server, $web, $placement] = rollingOut();
    $w = $placement->workload_id;
    $events = [
        ['seq' => 1, 'workload' => $w, 'at' => stamp(), 'kind' => 'created'],
        ['seq' => 2, 'workload' => $w, 'at' => stamp(), 'kind' => 'started'],
        ['seq' => 3, 'workload' => $w, 'at' => stamp(), 'kind' => 'health_failing', 'failures' => 3, 'message' => 'connection refused'],
    ];
    Projects::report($server, 'starting', events: $events);
    Projects::report($server, 'starting', events: $events); // re-sent: stored once

    $lines = resolve(DeploymentLogs::class)->page(latestOf($web))->lines;
    $texts = array_map(static fn (LogLineData $line): string => $line->text, $lines);

    expect($texts)->toBe([
        "Container created (replica 1, {$server->name})",
        "Container started (replica 1, {$server->name})",
        "Health check failed: GET / on :8000 → connection refused (3/3, replica 1, {$server->name})",
    ])
        ->and($lines[0]->stream)->toBe('system')
        ->and($lines[0]->section)->toBe('rollout');
});

test('a failed rollout keeps its lifecycle lines after its placement is gone', function (): void {
    [$server, $web, $placement] = rollingOut();
    $w = $placement->workload_id;
    Projects::report($server, 'starting', fields: [$w => ['issue' => ['kind' => 'crash_loop', 'since' => stamp(), 'restarts' => 3]]]);
    expect(Placement::query()->where('workload_id', $w)->exists())->toBeFalse();

    Projects::report($server, 'starting', events: [['seq' => 20, 'workload' => $w, 'at' => stamp(), 'kind' => 'removed', 'reason' => 'not_desired']]);

    $texts = array_map(static fn (LogLineData $line): string => $line->text, resolve(DeploymentLogs::class)->page(latestOf($web))->lines);
    expect($texts)->toContain("Container removed by launchroom (the deployment didn't go live, {$server->name})");
});

test('a service without an observed container is not running', function (): void {
    [$server, $web, $placement] = rollingOut();
    Projects::report($server);
    Projects::report($server);
    $instance = ServiceInstance::query()->where('service_id', $web->id)->with('activeDeployment', 'service')->sole();
    expect(latestOf($web)->status)->toBe(DeploymentStatus::Active);

    $active = Placement::query()->where('service_instance_id', $instance->id)->get();
    expect(NodeStatus::for($instance, latestOf($web), $active)->label)->toBe('Live');

    // Its server lost the container (or never created it).
    $active->each(static fn (Placement $p) => $p->forceFill(['phase' => WorkloadPhase::Pending, 'created_at' => now()->subHour()])->save());
    $status = NodeStatus::for($instance, latestOf($web), $active->fresh());
    expect($status->state)->toBe(CanvasNodeState::Warning)->and($status->label)->toBe('Not running');

    expect(NodeStatus::for($instance, latestOf($web), [])->label)->toBe('Not running');
});
