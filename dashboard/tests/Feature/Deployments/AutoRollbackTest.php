<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Deployments\SweepDeployments;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\AuditAction;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Support\Deployments\ProbationScope;
use Illuminate\Support\Facades\Date;
use Tests\Support\Projects;

/*
 * Probation after go-live (docs/04 §8, OD1): a production deployment of a
 * new version that crash-loops or goes down within 10 minutes is rolled
 * back once, health-gated, with production auto-deploy paused.
 */

beforeEach(fn () => Projects::useTestKek());
afterEach(fn () => Date::setTestNow());

/**
 * A production image service with two live versions: v1 superseded, v2 live and in probation.
 *
 * @return array{Server, Service, ServiceInstance, Deployment, Deployment}
 */
function probationApp(): array
{
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $project = Projects::create($team, 'shop-'.bin2hex(random_bytes(3)));
    $web = Projects::image($project, 'web', port: 8000);
    $instance = ServiceInstance::query()->where('service_id', $web->id)->sole();

    probationDeploy($instance, $server);
    $v1 = Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail();

    resolve(UpdateServiceSettings::class)->handle($instance->refresh(), UpdateServiceSettingsData::from(['deploy' => [...$instance->deploy->toArray(), 'drainSeconds' => 20]]));
    probationDeploy($instance, $server);
    $v2 = Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail();

    return [$server, $web, $instance, $v1->refresh(), $v2->refresh()];
}

function probationDeploy(ServiceInstance $instance, Server $server): void
{
    resolve(DeployEnvironment::class)->handle($instance->environment, null, AuditActor::system());

    for ($i = 0; $i < 4; $i++) {
        Projects::report($server);
    }
}

function crashLoop(Server $server, Deployment $deployment): void
{
    $w = $deployment->placements()->sole()->workload_id;
    Projects::report($server, 'unhealthy', fields: [$w => ['restartCount' => 4, 'issue' => ['kind' => 'crash_loop', 'since' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'exitCode' => 1, 'restarts' => 4]]]);
}

test('a new production version is watched for 10 minutes after it went live', function (): void {
    [, , , $v1, $v2] = probationApp();

    expect($v2->status)->toBe(DeploymentStatus::Active)
        ->and($v2->probation_until?->between(now()->addMinutes(9), now()->addMinutes(11)))->toBeTrue()
        // The first deploy had nothing to return to.
        ->and($v1->probation_until)->toBeNull();
});

test('a crash loop in probation rolls back once, pauses auto-deploy and says why', function (): void {
    [$server, , $instance, $v1, $v2] = probationApp();

    crashLoop($server, $v2);

    $rollback = Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail();
    expect($rollback->trigger)->toBe(DeploymentTrigger::Rollback)
        ->and($rollback->rollback_of_id)->toBe($v1->id)
        ->and($rollback->rollback_reason)->toBe("{$v2->public_id} kept crashing (exited with code 1, restarted 4 times) after it went live, so launchroom rolled back to {$v1->public_id}.")
        ->and($instance->environment->refresh()->auto_deploy_paused)->toBeTrue()
        ->and($instance->refresh()->last_auto_rollback_at)->not->toBeNull()
        ->and($v2->refresh()->inProbation())->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::DeploymentRolledBack->value)->latest('id')->firstOrFail()->meta['automatic'] ?? null)->toBeTrue();

    // The rollback is health-gated like any deploy and isn't watched itself.
    for ($i = 0; $i < 4; $i++) {
        Projects::report($server);
    }

    expect($rollback->refresh()->status)->toBe(DeploymentStatus::Active)
        ->and($rollback->probation_until)->toBeNull()
        ->and($v2->refresh()->status)->toBe(DeploymentStatus::Superseded);
});

test('every replica down for 2 minutes rolls back; a short dip doesn\'t', function (): void {
    [$server, , $instance, , $v2] = probationApp();
    $w = $v2->placements()->sole()->workload_id;

    Projects::report($server, phases: [$w => 'exited']);
    expect(Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail()->id)->toBe($v2->id);

    Date::setTestNow(now()->addSeconds(130));
    Projects::report($server, phases: [$w => 'exited']);
    // Nothing changed in the report: the minute sweep notices.
    resolve(SweepDeployments::class)->handle();

    expect(Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail()->rollback_reason)->toContain('was down for 2 minutes');
});

test('nothing happens after the window, in other environments, or a second time within the hour', function (): void {
    [$server, , $instance, , $v2] = probationApp();

    Date::setTestNow(now()->addMinutes(11));
    resolve(SweepDeployments::class)->handle();
    crashLoop($server, $v2);
    expect(Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail()->id)->toBe($v2->id);

    // Once an hour per service.
    Date::setTestNow();
    $v2->forceFill(['probation_until' => now()->addMinutes(5)])->save();
    $instance->forceFill(['last_auto_rollback_at' => now()->subMinutes(30)])->save();
    crashLoop($server, $v2);
    resolve(SweepDeployments::class)->handle();
    expect(Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail()->id)->toBe($v2->id)
        ->and($v2->refresh()->inProbation())->toBeFalse();
});

test('preview and staging environments are not watched', function (): void {
    [, , $instance, , $v2] = probationApp();
    $instance->environment->forceFill(['kind' => 'persistent'])->save();
    $v2->forceFill(['probation_until' => null])->save();

    expect(ProbationScope::watches($v2->refresh()->load('environment'), $instance->environment->lastAppliedRevision->spec->service($instance->service->public_id)))->toBeFalse();
});

test('a newer deployment in flight wins over the rollback', function (): void {
    [$server, , $instance, , $v2] = probationApp();
    resolve(UpdateServiceSettings::class)->handle($instance->refresh(), UpdateServiceSettingsData::from(['deploy' => [...$instance->deploy->toArray(), 'drainSeconds' => 30]]));
    resolve(DeployEnvironment::class)->handle($instance->environment, null, AuditActor::system());
    $fix = Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail();

    crashLoop($server, $v2);

    expect(Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail()->id)->toBe($fix->id)
        ->and(Placement::query()->where('deployment_id', $v2->id)->exists())->toBeTrue();
});
