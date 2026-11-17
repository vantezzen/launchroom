<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Data\Audit\AuditActor;
use App\Data\Deployments\ContainerExitData;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentStepName;
use App\Enums\WorkloadFailureCode;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Tests\Support\Projects;

/*
 * A start failure launchroomd typed as one waiting can't fix (exit 127 from
 * the container's init, a wrong platform, an unknown user, a missing or
 * private image) fails the rollout at once instead of after the crash
 * loop or the timeout (h-runtime A2 request).
 */

beforeEach(fn () => Projects::useTestKek());

/**
 * @return array{0: Server, 1: Service, 2: Placement}
 */
function fatalRollingOut(): array
{
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $project = Projects::create($team, 'shop');
    $web = Projects::image($project, 'web', port: 8000);
    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());

    return [$server, $web, fatalLatestOf($web)->placements()->sole()];
}

function fatalLatestOf(Service $service): Deployment
{
    return Deployment::query()->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $service->id))->latest('id')->firstOrFail();
}

function fatalExit(Placement $placement, WorkloadFailureCode $code, ?int $exitCode = null): void
{
    $placement->forceFill(['last_exit' => new ContainerExitData(
        at: now()->utc()->format('Y-m-d\TH:i:s\Z'),
        code: $exitCode,
        output: [['time' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'stream' => 'stderr', 'text' => '[FATAL tini (7)] exec bundle failed: No such file or directory']],
        failure: $code,
        message: 'exec bundle failed: No such file or directory',
    )])->save();
}

test('a start command that does not exist fails the rollout on the first exit', function (): void {
    [$server, $web, $placement] = fatalRollingOut();
    fatalExit($placement, WorkloadFailureCode::CommandNotFound, 127);

    Projects::report($server, 'unhealthy', fields: [$placement->workload_id => ['restartCount' => 1]]);

    $deployment = fatalLatestOf($web);
    $step = $deployment->steps()->whereIn('name', [DeploymentStepName::Rollout->value, DeploymentStepName::Health->value])->where('status', 'failed')->sole();
    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_class)->toBe(DeploymentFailureClass::RuntimeError)
        ->and($deployment->failure_message)->toContain('exited with code 127')
        ->and($step->meta['failureCode'])->toBe('command_not_found');
});

test('a private image the server may not pull is not retried as a transient failure', function (): void {
    [$server, $web, $placement] = fatalRollingOut();
    fatalExit($placement, WorkloadFailureCode::Unauthorized);

    Projects::report($server, 'failed', messages: [$placement->workload_id => 'pull failed: unauthorized: authentication required']);

    $deployment = fatalLatestOf($web);
    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_class)->toBe(DeploymentFailureClass::RuntimeError);
});

test('a rate-limited pull stays a transient failure, and a typed failure that may heal keeps waiting', function (): void {
    [$server, $web, $placement] = fatalRollingOut();
    fatalExit($placement, WorkloadFailureCode::NoSpace);

    Projects::report($server, 'unhealthy', fields: [$placement->workload_id => ['restartCount' => 1]]);
    expect(fatalLatestOf($web)->status)->not->toBe(DeploymentStatus::Failed);

    fatalExit($placement->refresh(), WorkloadFailureCode::RateLimited);
    Projects::report($server, 'failed', messages: [$placement->workload_id => 'pull failed: toomanyrequests']);

    expect(fatalLatestOf($web)->failure_class)->toBe(DeploymentFailureClass::Transient);
});
