<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Deployments\SweepDeployments;
use App\Data\Audit\AuditActor;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentStepName;
use App\Events\Broadcasts\DeploymentUpdated;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Queries\DeploymentDetailView;
use App\Support\Deployments\PullProgressMessage;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Projects;

/*
 * Large first pulls (docs/04 §11 "Pulls"): launchroomd reports the pull's
 * progress on the workload, the rollout step shows it, and the health
 * deadline waits while the pull moves.
 */

beforeEach(fn () => Projects::useTestKek());
afterEach(fn () => Date::setTestNow());

/**
 * @return array{0: Server, 1: Service, 2: Placement}
 */
function pullingDeployment(): array
{
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $project = Projects::create($team, 'shop');
    $web = Projects::image($project, 'web');
    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());
    $placement = pullDeploymentOf($web)->placements()->sole();

    return [$server, $web, $placement];
}

function pullDeploymentOf(Service $service): Deployment
{
    return Deployment::query()->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $service->id))->latest('id')->firstOrFail();
}

function rolloutStepMessage(Deployment $deployment): ?string
{
    $steps = resolve(DeploymentDetailView::class)->get($deployment)->steps;

    foreach ($steps as $step) {
        if ($step->name === DeploymentStepName::Rollout) {
            return $step->message;
        }
    }

    return null;
}

test('pull progress lands on the placement and in the running rollout step', function (): void {
    [$server, $web, $placement] = pullingDeployment();
    $w = $placement->workload_id;

    Projects::report($server, 'pulling', pulls: [$w => ['stage' => 'mirroring', 'downloadedBytes' => 0, 'totalBytes' => 0]]);
    expect($placement->refresh()->pull)->toEqual(['stage' => 'mirroring', 'downloaded' => 0, 'total' => 0])
        ->and(rolloutStepMessage(pullDeploymentOf($web)))->toBe('Fetching image into the local registry (first deploy of this image)');

    Projects::report($server, 'pulling', pulls: [$w => ['stage' => 'downloading', 'downloadedBytes' => 412_400_000, 'totalBytes' => 690_100_000]]);
    expect(rolloutStepMessage(pullDeploymentOf($web)))->toBe('Downloading image (412 MB of 690 MB)');

    // Once the container exists, the pull is gone from the placement and the step.
    Projects::report($server, 'starting');
    expect($placement->refresh()->pull)->toBeNull()
        ->and(rolloutStepMessage(pullDeploymentOf($web)))->toBeNull();
});

test('progress alone nudges open pages without advancing the deployment', function (): void {
    [$server, $web, $placement] = pullingDeployment();
    $w = $placement->workload_id;
    Projects::report($server, 'pulling', pulls: [$w => ['stage' => 'downloading', 'downloadedBytes' => 1_000_000, 'totalBytes' => 690_000_000]]);
    Queue::fake();

    Projects::report($server, 'pulling', pulls: [$w => ['stage' => 'downloading', 'downloadedBytes' => 300_000_000, 'totalBytes' => 690_000_000]]);

    $deployment = pullDeploymentOf($web);
    Queue::assertNotPushed(AdvanceDeploymentJob::class);
    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof DeploymentUpdated
        && $job->event->deploymentId === $deployment->public_id
        && $job->event->status === $deployment->status->value);
});

test('the health deadline waits while the image is still pulling', function (): void {
    [$server, $web, $placement] = pullingDeployment();
    $w = $placement->workload_id;

    // An hour of pulling with progress: still rolling out.
    for ($minutes = 0; $minutes < 60; $minutes += 2) {
        Date::setTestNow(now()->addMinutes(2));
        Projects::report($server, 'pulling', pulls: [$w => ['stage' => 'downloading', 'downloadedBytes' => $minutes * 10_000_000, 'totalBytes' => 690_000_000]]);
        resolve(SweepDeployments::class)->handle();
    }
    expect(pullDeploymentOf($web)->status)->toBe(DeploymentStatus::RollingOut);

    // The pull finished; the container never got healthy: the deadline applies from then.
    Projects::report($server, 'starting');
    Date::setTestNow(now()->addMinutes(30));
    resolve(SweepDeployments::class)->handle();
    expect(pullDeploymentOf($web)->failure_class)->toBe(DeploymentFailureClass::HealthTimeout);
});

test('a placement that stopped reporting while pulling fails rollout', function (): void {
    [$server, $web, $placement] = pullingDeployment();
    Projects::report($server, 'pulling', pulls: [$placement->workload_id => ['stage' => 'mirroring', 'downloadedBytes' => 0, 'totalBytes' => 0]]);

    Date::setTestNow(now()->addHours(2));
    resolve(SweepDeployments::class)->handle();

    expect(pullDeploymentOf($web)->failure_class)->toBe(DeploymentFailureClass::Transient);
});

test('pull messages: the slowest server speaks, sizes in decimal units', function (): void {
    $placement = static function (string $stage, int $downloaded, int $total): Placement {
        $p = new Placement;
        $p->forceFill(['phase' => 'pulling', 'pull' => ['stage' => $stage, 'downloaded' => $downloaded, 'total' => $total]]);

        return $p;
    };

    expect(PullProgressMessage::for([$placement('downloading', 600_000_000, 690_000_000), $placement('downloading', 100_000_000, 690_000_000)]))
        ->toBe('Downloading image (100 MB of 690 MB) on 2 servers')
        ->and(PullProgressMessage::for([$placement('extracting', 2_400_000_000, 2_400_000_000)]))->toBe('Unpacking image (2.4 GB)')
        ->and(PullProgressMessage::for([$placement('downloading', 5, 0)]))->toBe('Downloading image')
        ->and(PullProgressMessage::for([]))->toBeNull()
        ->and(PullProgressMessage::bytes(400_000))->toBe('< 1 MB');
});
