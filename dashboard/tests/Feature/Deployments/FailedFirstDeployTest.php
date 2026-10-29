<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentStepName;
use App\Enums\DeploymentStepStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\TeamRole;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Services\Deployments\ReleaseStep;
use App\Support\Services\StagedForService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DeploymentUi;
use Tests\Support\PreviewFlow;
use Tests\Support\Projects;

/*
 * A service whose first deployment failed (docs/04 §8.1, docs/08 §13): the
 * failed deployment stays in its Deployments tab with its error and steps,
 * nothing reads as staged (the layout was applied), and the header keeps
 * Deploy to try again while Restart and Stop wait for something to run.
 */

beforeEach(fn () => Projects::useTestKek());

/**
 * The shop deployed once, with every first deployment failed in its rollout.
 */
function failedFirstDeploy(): DeploymentUi
{
    $world = DeploymentUi::make(deploy: false);
    resolve(DeployEnvironment::class)->handle($world->production, null, AuditActor::system());

    foreach ([$world->web, $world->postgres] as $service) {
        $deployment = $world->latest($service);
        DeploymentStep::query()->updateOrCreate([
            'deployment_id' => $deployment->id,
            'name' => DeploymentStepName::Rollout,
        ], [
            'status' => DeploymentStepStatus::Failed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'meta' => ['message' => 'The container keeps crashing'],
        ]);
        $deployment->transitionTo(DeploymentStatus::Failed, DeploymentFailureClass::RuntimeError, 'The container keeps crashing: it exited with code 1 and was restarted 3 times.');
    }

    return $world;
}

function firstDeployInstance(DeploymentUi $world, Service $service): ServiceInstance
{
    return ServiceInstance::query()->where('service_id', $service->id)->where('environment_id', $world->production->id)->sole();
}

test('the failed first deployment stays in the list as its expanded row, with its error and steps', function (): void {
    $world = failedFirstDeploy();
    $failed = $world->latest($world->web);

    $json = $this->actingAs($world->member(TeamRole::Developer))
        ->getJson(route('deployments.service', $world->serviceArgs()))
        ->assertOk()
        ->json();

    expect($json['active'])->toBeNull()
        ->and($json['deployments'])->toHaveCount(1)
        ->and($json['deployments'][0]['id'])->toBe($failed->public_id)
        ->and($json['deployments'][0]['status'])->toBe('failed')
        ->and($json['deployments'][0]['failureSummary'])->toContain('keeps crashing')
        ->and($json['current']['id'])->toBe($failed->public_id)
        ->and(collect($json['current']['steps'])->firstWhere('name', 'rollout'))->toMatchArray(['status' => 'failed'])
        ->and($json['current']['expectsRelease'])->toBeFalse()
        ->and($json['canDeploy'])->toBeTrue();
});

test('a cancelled first deployment stays in the list too', function (): void {
    $world = DeploymentUi::make(deploy: false);
    resolve(DeployEnvironment::class)->handle($world->production, null, AuditActor::system());
    $cancelled = $world->latest($world->web);
    $cancelled->transitionTo(DeploymentStatus::Cancelled);

    $json = $this->actingAs($world->member(TeamRole::Viewer))
        ->getJson(route('deployments.service', $world->serviceArgs()))
        ->assertOk()
        ->json();

    expect($json['deployments'][0]['status'])->toBe('cancelled')
        ->and($json['current']['id'])->toBe($cancelled->public_id);
});

test('a failed first deploy of an applied layout stages nothing', function (): void {
    $world = failedFirstDeploy();
    $instance = firstDeployInstance($world, $world->web);

    expect($world->production->refresh()->last_applied_revision_id)->not->toBeNull()
        ->and(resolve(StagedForService::class)->has($instance))->toBeFalse();

    $this->actingAs($world->member(TeamRole::Developer))
        ->get(route('projects.services.show', [...$world->serviceArgs(), 'tab' => 'deployments']))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('panel.controls.canControl', true)
            ->where('panel.controls.running', false)
            ->where('panel.controls.stopped', false)
            ->where('panel.controls.staged', false)
            ->where('panel.controls.deploying', false));
});

test('Deploy tries a failed first deploy again, for an image service and a database', function (): void {
    $world = failedFirstDeploy();
    $member = $world->member(TeamRole::Developer);

    foreach ([$world->web, $world->postgres] as $service) {
        $failed = $world->latest($service);
        $this->actingAs($member)->post(route('services.lifecycle.deploy', $world->serviceArgs($service)))->assertRedirect();
        $retry = $world->latest($service);

        expect($retry->id)->not->toBe($failed->id)
            ->and($retry->trigger)->toBe(DeploymentTrigger::Manual)
            ->and($retry->revision_id)->toBe($failed->revision_id);
    }

    // Once the database runs, Deploy is Restart's job again.
    for ($i = 0; $i < 4; $i++) {
        Projects::report($world->server);
    }

    expect($world->latest($world->postgres)->status)->toBe(DeploymentStatus::Active);
    $this->actingAs($member)->from('/')->post(route('services.lifecycle.deploy', $world->serviceArgs($world->postgres)))->assertSessionHasErrors();
});

test('Restart and Stop stay refused while nothing runs', function (): void {
    $world = failedFirstDeploy();
    $member = $world->member(TeamRole::Developer);

    $this->actingAs($member)->from('/')->post(route('services.lifecycle.restart', $world->serviceArgs()))->assertSessionHasErrors();
    expect(Deployment::query()->where('service_instance_id', firstDeployInstance($world, $world->web)->id)->count())->toBe(1);
});

test('the Release step is expected for a release command, a build that brings one or a likely Laravel plan', function (): void {
    $world = DeploymentUi::make(deploy: false);
    resolve(DeployEnvironment::class)->handle($world->production, null, AuditActor::system());
    $deployment = $world->latest($world->web);

    expect(ReleaseStep::expected($deployment))->toBeFalse();

    // An app whose plan preview found Laravel: likely, before the build.
    config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures']]);
    $app = PreviewFlow::gitApp($world->project, 'shop');
    resolve(DeployEnvironment::class)->handle($world->production, null, AuditActor::system());
    $planned = $world->latest($app);
    expect(ReleaseStep::expected($planned))->toBeFalse();
    firstDeployInstance($world, $app)->forceFill(['plan' => ['status' => 'ready', 'summary' => ['builder' => 'railpack', 'builderReason' => 'railpack', 'framework' => 'laravel']]])->save();
    expect(ReleaseStep::expected($planned->fresh()))->toBeTrue();

    // Restarts never run it.
    $planned->trigger = DeploymentTrigger::Restart;
    expect(ReleaseStep::expected($planned))->toBeFalse();

    // A release command of the service's own.
    $instance = firstDeployInstance($world, $world->web);
    $deploy = clone $instance->deploy;
    $deploy->releaseCommand = ['php', 'artisan', 'migrate', '--force'];
    resolve(UpdateServiceSettings::class)->handle($instance, new UpdateServiceSettingsData(deploy: $deploy));
    resolve(DeployEnvironment::class)->handle($world->production, null, AuditActor::system());

    expect(ReleaseStep::expected($world->latest($world->web)))->toBeTrue();
});
