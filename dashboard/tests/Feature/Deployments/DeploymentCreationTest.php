<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Deployments\RedeployService;
use App\Actions\Deployments\RollbackDeployment;
use App\Actions\Moves\PrebuildForPlatform;
use App\Data\Audit\AuditActor;
use App\Data\Deployments\DeploymentPlanData;
use App\Enums\DeploymentPurpose;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\EnvironmentKind;
use App\Enums\VariableType;
use App\Events\DeploymentQueued;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Variable;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Variables\ProductionSecretPolicy;
use App\Support\Refusal;
use App\Support\Variables\DeploymentSystemEnv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

/**
 * @return array{Environment, Service, ServiceInstance}
 */
function dcProject(): array
{
    $team = Team::factory()->create();
    Projects::server($team);
    $project = Projects::create($team, 'shop');
    $web = Projects::image($project, 'web');
    $production = $project->production()->sole();

    return [$production, $web, ServiceInstance::query()->where('environment_id', $production->id)->where('service_id', $web->id)->sole()];
}

function dcLatest(ServiceInstance $instance): Deployment
{
    return Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail();
}

test('every created deployment is announced once, after commit, and its listener starts it', function (): void {
    [$production, , $instance] = dcProject();
    Queue::fake([AdvanceDeploymentJob::class]);

    DB::transaction(function () use ($production): void {
        resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
        Queue::assertNotPushed(AdvanceDeploymentJob::class);
    });

    $deployment = dcLatest($instance);
    Queue::assertPushed(AdvanceDeploymentJob::class, 1);
    Queue::assertPushed(AdvanceDeploymentJob::class, static fn (AdvanceDeploymentJob $job): bool => $job->deploymentId === $deployment->id);

    Event::fake([DeploymentQueued::class]);
    resolve(RedeployService::class)->handle($instance, null, null, AuditActor::system());
    Event::assertDispatchedTimes(DeploymentQueued::class, 1);
});

test('a new deployment supersedes older queued ones in one place, also for a rollback', function (): void {
    [$production, , $instance] = dcProject();
    Queue::fake([AdvanceDeploymentJob::class]);
    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
    $first = dcLatest($instance);
    $first->forceFill(['image' => 'registry.test/web@sha256:'.str_repeat('a', 64)])->save();
    $first->transitionTo(DeploymentStatus::Building);
    $first->transitionTo(DeploymentStatus::Superseded);
    $first->forceFill(['went_live_at' => now()])->save();

    $queued = resolve(RedeployService::class)->handle($instance, null, null, AuditActor::system());
    $rollback = resolve(RollbackDeployment::class)->handle($first->refresh(), null, AuditActor::system());

    expect($queued->refresh()->status)->toBe(DeploymentStatus::Superseded)
        ->and($queued->superseded_by_id)->toBe($rollback->id)
        ->and($rollback->status)->toBe(DeploymentStatus::Queued)
        ->and($rollback->rollback_of_id)->toBe($first->id)
        ->and($rollback->trigger)->toBe(DeploymentTrigger::Rollback)
        ->and($rollback->image)->toBe($first->image);
});

test('a rerun carries the environment but not the source deployment’s own system variables', function (): void {
    [$production, , $instance] = dcProject();
    Queue::fake([AdvanceDeploymentJob::class]);
    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
    $first = dcLatest($instance);
    $first->forceFill(['image' => 'registry.test/web@sha256:'.str_repeat('a', 64), 'plan' => new DeploymentPlanData(platform: 'linux/amd64')])->save();
    $first->transitionTo(DeploymentStatus::Building);
    $first->transitionTo(DeploymentStatus::Superseded);
    $first->forceFill(['went_live_at' => now()])->save();

    $rollback = resolve(RollbackDeployment::class)->handle($first->refresh(), null, AuditActor::system());
    $env = resolve(ProjectKeyring::class)->decryptSnapshot($production->project, $rollback->public_id, (string) $rollback->env_snapshot);

    expect($env['LAUNCHROOM_DEPLOYMENT_ID'])->toBe($rollback->public_id)
        ->and($env)->not->toHaveKey('LAUNCHROOM_GIT_COMMIT_SHA')
        ->and($rollback->plan?->platform)->toBe('linux/amd64');

    expect(DeploymentSystemEnv::add(['A' => '1', 'LAUNCHROOM_DEPLOYMENT_ID' => 'dpl_old'], 'dpl_new', 'abc'))
        ->toBe(['A' => '1', 'LAUNCHROOM_DEPLOYMENT_ID' => 'dpl_new', 'LAUNCHROOM_GIT_COMMIT_SHA' => 'abc'])
        ->and(DeploymentSystemEnv::strip(['A' => '1', 'LAUNCHROOM_DEPLOYMENT_ID' => 'x', 'LAUNCHROOM_GIT_COMMIT_SHA' => 'y']))->toBe(['A' => '1']);
});

test('prebuilds and import preparations are typed by purpose and never collapse a release', function (): void {
    [$production, , $instance] = dcProject();
    Queue::fake([AdvanceDeploymentJob::class]);
    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
    $release = dcLatest($instance);

    expect($release->purpose)->toBe(DeploymentPurpose::Release)
        ->and(resolve(PrebuildForPlatform::class)->handle($instance, 'linux/arm64', null))->toBeInstanceOf(Refusal::class);

    $prebuild = $release->replicate(['public_id', 'env_snapshot']);
    $prebuild->forceFill(['purpose' => DeploymentPurpose::Prebuild, 'target_platform' => 'linux/arm64', 'status' => DeploymentStatus::Queued])->save();
    resolve(RedeployService::class)->handle($instance, null, null, AuditActor::system());

    expect($prebuild->refresh()->status)->toBe(DeploymentStatus::Queued)
        ->and(Deployment::query()->withPurpose(DeploymentPurpose::Prebuild)->pluck('id')->all())->toBe([$prebuild->id]);
});

test('plan markers move into the purpose columns', function (): void {
    [$production, , $instance] = dcProject();
    Queue::fake([AdvanceDeploymentJob::class]);
    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
    resolve(RedeployService::class)->handle($instance, null, null, AuditActor::system());
    [$prebuild, $preparation] = Deployment::query()->where('service_instance_id', $instance->id)->orderBy('id')->pluck('id')->all();

    $migration = require database_path('migrations/2026_12_28_100000_add_deployment_purpose.php');
    $migration->down();
    DB::table('deployments')->where('id', $prebuild)->update(['plan' => json_encode(['prebuildFor' => 'linux/arm64', 'task' => 'tsk_1'])]);
    DB::table('deployments')->where('id', $preparation)->update(['plan' => json_encode(['prepareImport' => ['until' => '2030-01-02T03:04:05+00:00', 'by' => 1]])]);
    $migration->up();

    $prebuild = Deployment::query()->findOrFail($prebuild);
    $preparation = Deployment::query()->findOrFail($preparation);

    expect($prebuild->purpose)->toBe(DeploymentPurpose::Prebuild)
        ->and($prebuild->target_platform)->toBe('linux/arm64')
        ->and($prebuild->plan?->task)->toBe('tsk_1')
        ->and($preparation->purpose)->toBe(DeploymentPurpose::PrepareImport)
        ->and($preparation->prepared_until?->toIso8601ZuluString())->toBe('2030-01-02T03:04:05Z')
        ->and($preparation->plan)->toBeNull();
});

test('the secrets policy refuses deploys of production and previews only', function (): void {
    [$production, $web] = dcProject();
    $project = $production->project;
    $project->forceFill(['settings' => ['productionSecretsMustDiffer' => true]])->save();
    $row = new Variable;
    $row->forceFill(['team_id' => $project->team_id, 'project_id' => $project->id, 'service_id' => $web->id, 'key' => 'API_TOKEN', 'type' => VariableType::Secret, 'version' => 1, 'value' => 'x'])->save();
    $row->forceFill(['value' => resolve(ProjectKeyring::class)->encryptVariable($project, $row->public_id, 1, 'tok')])->save();
    $row->targets()->create(['environment_id' => $production->id, 'all_previews' => false]);
    $row->targets()->create(['environment_id' => null, 'all_previews' => true]);

    $policy = resolve(ProductionSecretPolicy::class);
    $persistent = $production->replicate();
    $persistent->kind = EnvironmentKind::Persistent;

    expect($policy->violationsFor($production))->not->toBe([])
        ->and($policy->violationsFor($persistent))->toBe([])
        ->and(fn () => resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system()))->toThrow(DomainRuleViolation::class, 'must differ');
});

test('only the factory constructs deployments', function (): void {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'Services/Deployments/DeploymentFactory.php')) {
            continue;
        }

        $code = (string) file_get_contents($file->getPathname());

        if (preg_match('/Deployment::(query\(\)->)?create\(|new Deployment\b(?!\w)/', $code) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});
