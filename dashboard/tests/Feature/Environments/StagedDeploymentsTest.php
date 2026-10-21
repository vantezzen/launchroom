<?php

declare(strict_types=1);

use App\Actions\Deployments\CancelDeployment;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Deployments\PromoteStagedDeployment;
use App\Actions\Variables\CreateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Enums\AuditAction;
use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Enums\VariableType;
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
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

/**
 * @return array{Server, Project, Environment, Service, Service}
 */
function sdProject(): array
{
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $project = Projects::create($team, 'shop');
    $postgres = Projects::postgres($project);
    $web = Projects::image($project, 'web');
    $production = $project->production()->sole();

    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());

    for ($i = 0; $i < 4; $i++) {
        Projects::report($server);
    }

    return [$server, $project, $production, $postgres, $web];
}

function sdLatest(Service $service): Deployment
{
    return Deployment::query()->whereIn('service_instance_id', ServiceInstance::query()->select('id')->where('service_id', $service->id))->latest('id')->firstOrFail();
}

function sdStage(Project $project, Environment $production, Service $web, Service $postgres): void
{
    foreach ([$web, $postgres] as $service) {
        resolve(CreateVariable::class)->handle($project, $service, VariableInput::from([
            'key' => 'GREETING', 'value' => 'hello', 'type' => VariableType::Config, 'targets' => [VariableTargetData::environment($production->public_id)],
        ]), null, AuditActor::system());
    }

    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system(), holdTraffic: true);
}

test('a staged deployment is built and started but not routed until promoted', function (): void {
    [$server, $project, $production, $postgres, $web] = sdProject();
    $live = sdLatest($web);
    $liveWorkload = Placement::query()->where('deployment_id', $live->id)->sole()->workload_id;

    sdStage($project, $production, $web, $postgres);
    $staged = sdLatest($web);

    Projects::report($server);
    Projects::report($server);

    $instance = ServiceInstance::query()->where('service_id', $web->id)->sole();
    $routes = Projects::domainRoutes($server);

    expect($staged->refresh()->status)->toBe(DeploymentStatus::Ready)
        ->and($staged->hold_traffic)->toBeTrue()
        ->and($staged->status->label())->toBe('Ready to promote')
        ->and($instance->active_deployment_id)->toBe($live->id)
        ->and(collect($routes[0]['upstreams'])->pluck('workload')->all())->toBe([$liveWorkload])
        // The database can't run twice: it deploys as usual.
        ->and(sdLatest($postgres)->hold_traffic)->toBeFalse()
        ->and(sdLatest($postgres)->status)->toBe(DeploymentStatus::Active);

    $this->actingAs(memberOf($project->team, TeamRole::Viewer))
        ->post("/p/{$project->public_id}/{$production->public_id}/d/{$staged->public_id}/promote")
        ->assertForbidden();

    $this->actingAs(memberOf($project->team, TeamRole::Developer))
        ->post("/p/{$project->public_id}/{$production->public_id}/d/{$staged->public_id}/promote")
        ->assertRedirect("/p/{$project->public_id}/{$production->public_id}/d/{$staged->public_id}");

    for ($i = 0; $i < 3; $i++) {
        Projects::report($server);
    }

    expect($staged->refresh()->status)->toBe(DeploymentStatus::Active)
        ->and($live->refresh()->status)->toBe(DeploymentStatus::Superseded)
        ->and($instance->refresh()->active_deployment_id)->toBe($staged->id)
        ->and(AuditEvent::query()->where('action', AuditAction::DeploymentPromoted->value)->exists())->toBeTrue()
        ->and(fn () => resolve(PromoteStagedDeployment::class)->handle($staged, AuditActor::system()))->toThrow(DomainRuleViolation::class, 'Only a staged deployment');
});

test('cancelling a staged deployment removes its workloads; the live version keeps serving', function (): void {
    [$server, $project, $production, $postgres, $web] = sdProject();
    $live = sdLatest($web);
    sdStage($project, $production, $web, $postgres);
    $staged = sdLatest($web);
    Projects::report($server);
    Projects::report($server);

    expect($staged->refresh()->status)->toBe(DeploymentStatus::Ready)
        ->and($staged->status->isCancellable())->toBeTrue();

    resolve(CancelDeployment::class)->handle($staged, AuditActor::system());

    expect($staged->refresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and(Placement::query()->where('deployment_id', $staged->id)->exists())->toBeFalse()
        ->and($live->refresh()->status)->toBe(DeploymentStatus::Active)
        ->and(collect(Projects::nodeState($server)['workloads'])->where('service', $web->public_id))->toHaveCount(1);
});

test('the deploy endpoint stages with `stage`', function (): void {
    [, $project, $production, $postgres, $web] = sdProject();
    resolve(CreateVariable::class)->handle($project, $web, VariableInput::from([
        'key' => 'A', 'value' => 'b', 'type' => VariableType::Config, 'targets' => [VariableTargetData::environment($production->public_id)],
    ]), null, AuditActor::system());

    $this->actingAs(memberOf($project->team, TeamRole::Developer))
        ->post("/p/{$project->public_id}/{$production->public_id}/deploy", ['stage' => true])
        ->assertRedirect();

    expect(sdLatest($web)->hold_traffic)->toBeTrue();
});
