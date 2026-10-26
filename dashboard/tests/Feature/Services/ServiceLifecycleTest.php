<?php

declare(strict_types=1);

use App\Actions\Backups\RunBackupTick;
use App\Actions\Servers\RenderNodeState;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\AuditAction;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Backups\BackupRun;
use App\Models\Placement;
use App\Models\ServiceInstance;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/*
 * Stop, Start, Restart and Deploy of a service (D68, docs/04 §8.1, docs/08
 * §13): desired-state changes only, the environment's deploy permission,
 * audited.
 */

beforeEach(fn () => Projects::useTestKek());

function lifecycleInstance(DeploymentUi $world): ServiceInstance
{
    return ServiceInstance::query()->where('service_id', $world->web->id)->where('environment_id', $world->production->id)->sole();
}

test('stop: the containers leave the desired state, the domains answer stopped, everything else stays', function (): void {
    $world = DeploymentUi::make();
    $active = $world->latest($world->web);
    $instance = lifecycleInstance($world);
    expect(Placement::query()->where('service_instance_id', $instance->id)->count())->toBe(1);

    $this->actingAs($world->member(TeamRole::Viewer))->post(route('services.lifecycle.stop', $world->serviceArgs()))->assertForbidden();
    $this->actingAs($world->member(TeamRole::Developer))->post(route('services.lifecycle.stop', $world->serviceArgs()), ['processes' => true])->assertRedirect();

    $instance->refresh();
    $state = Projects::nodeState($world->server);
    $routes = Projects::domainRoutes($world->server);

    expect($instance->stopped_at)->not->toBeNull()
        ->and($instance->active_deployment_id)->toBe($active->id)
        ->and(Placement::query()->where('service_instance_id', $instance->id)->exists())->toBeFalse()
        ->and(collect($state['workloads'])->pluck('service'))->not->toContain($world->web->public_id)
        ->and(AuditEvent::query()->where('action', AuditAction::ServiceStopped->value)->exists())->toBeTrue();

    // Edges announcing serviceStop get the stopped route.
    $world->server->forceFill(['daemon_features' => ['serviceStop']])->save();
    resolve(RenderNodeState::class)->handle($world->server->refresh());
    $stopped = collect(Projects::domainRoutes($world->server))->firstWhere('stopped', true);
    expect($routes)->toBe([])
        ->and($stopped)->not->toBeNull()
        ->and($stopped['upstreams'])->toBe([]);

    // The panel shows it stopped with Start.
    $this->actingAs($world->member(TeamRole::Developer))
        ->get(route('projects.services.show', [...$world->serviceArgs(), 'tab' => 'deployments']))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('panel.controls.stopped', true)
            ->where('panel.controls.canControl', true));
});

test('start: a start deployment runs the stopped version again, without a build or release', function (): void {
    $world = DeploymentUi::make();
    $active = $world->latest($world->web);
    $member = $world->member(TeamRole::Developer);
    $this->actingAs($member)->post(route('services.lifecycle.stop', $world->serviceArgs()))->assertRedirect();

    $this->actingAs($member)->post(route('services.lifecycle.start', $world->serviceArgs()))->assertRedirect();

    $started = $world->latest($world->web);
    expect(lifecycleInstance($world)->stopped_at)->toBeNull()
        ->and($started->trigger)->toBe(DeploymentTrigger::Start)
        ->and($started->image)->toBe($active->image)
        ->and($started->revision_id)->toBe($active->revision_id)
        ->and(AuditEvent::query()->where('action', AuditAction::ServiceStarted->value)->exists())->toBeTrue();

    Projects::report($world->server);
    Projects::report($world->server);
    expect($world->latest($world->web)->status)->toBe(DeploymentStatus::Active);
});

test('restart: a restart deployment of the live version; refused while stopped or deploying', function (): void {
    $world = DeploymentUi::make();
    $active = $world->latest($world->web);
    $member = $world->member(TeamRole::Developer);

    $this->actingAs($member)->post(route('services.lifecycle.restart', $world->serviceArgs()))->assertRedirect();
    $restart = $world->latest($world->web);
    expect($restart->trigger)->toBe(DeploymentTrigger::Restart)
        ->and($restart->image)->toBe($active->image)
        ->and($restart->id)->not->toBe($active->id);

    // Another restart while this one runs: refused.
    $this->actingAs($member)->from('/')->post(route('services.lifecycle.restart', $world->serviceArgs()))->assertSessionHasErrors();

    Projects::report($world->server);
    Projects::report($world->server);
    expect($world->latest($world->web)->status)->toBe(DeploymentStatus::Active);

    $this->actingAs($member)->post(route('services.lifecycle.stop', $world->serviceArgs()))->assertRedirect();
    $this->actingAs($member)->from('/')->post(route('services.lifecycle.restart', $world->serviceArgs()))->assertSessionHasErrors();
});

test('deploy: a new version, unless changes are staged or the service is stopped', function (): void {
    $world = DeploymentUi::make();
    $active = $world->latest($world->web);
    $member = $world->member(TeamRole::Developer);

    $response = $this->actingAs($member)->post(route('services.lifecycle.deploy', $world->serviceArgs()));
    $created = $world->latest($world->web);
    $response->assertRedirect(route('deployments.show', $world->deploymentArgs($created)));
    expect($created->id)->not->toBe($active->id)->and($created->trigger)->toBe(DeploymentTrigger::Manual);
    Projects::report($world->server);
    Projects::report($world->server);

    // Staged changes go out with the environment's Deploy changes instead.
    $instance = lifecycleInstance($world);
    $deploy = clone $instance->deploy;
    $deploy->drainSeconds = 30;
    resolve(UpdateServiceSettings::class)->handle($instance, new UpdateServiceSettingsData(deploy: $deploy));
    $this->actingAs($member)->from('/')->post(route('services.lifecycle.deploy', $world->serviceArgs()))->assertSessionHasErrors();
});

test('a stopped service pauses its backups and cancels what it was deploying', function (): void {
    $world = DeploymentUi::make();
    $member = $world->member(TeamRole::Developer);
    $this->actingAs($member)->post(route('services.lifecycle.restart', $world->serviceArgs()))->assertRedirect();
    $restart = $world->latest($world->web);

    $this->actingAs($member)->post(route('services.lifecycle.stop', $world->serviceArgs()))->assertRedirect();

    expect($restart->refresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($restart->failure_message)->toContain('the service is stopped');

    // The database stops too; its scheduled backups are skipped, not failed.
    $postgres = ServiceInstance::query()->where('service_id', $world->postgres->id)->sole();
    $this->actingAs($member)->post(route('services.lifecycle.stop', $world->serviceArgs($world->postgres)))->assertRedirect();
    expect($postgres->refresh()->stopped_at)->not->toBeNull();
    resolve(RunBackupTick::class)->handle(today()->addHours(3));
    expect(BackupRun::query()->where('service_instance_id', $postgres->id)->exists())->toBeFalse();
});

test('a stopped service\'s live deployment says so: who stopped it and when, no Redeploy or Roll back', function (): void {
    $world = DeploymentUi::make();
    $active = $world->latest($world->web);
    $member = $world->member(TeamRole::Developer);
    $this->actingAs($member)->post(route('services.lifecycle.stop', $world->serviceArgs()))->assertRedirect();

    $list = $this->actingAs($member)->getJson(route('deployments.service', $world->serviceArgs()))->assertOk();
    expect($list->json('active.id'))->toBe($active->public_id)
        ->and($list->json('active.isActive'))->toBeTrue()
        ->and($list->json('active.stopped.by'))->toBe($member->name)
        ->and($list->json('active.stopped.at'))->not->toBeNull()
        ->and($list->json('active.canRollback'))->toBeFalse()
        ->and($list->json('canDeploy'))->toBeFalse();

    $this->actingAs($member)
        ->get(route('deployments.show', $world->deploymentArgs($active)))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('detail.deployment.stopped.by', $member->name)
            ->where('detail.canRedeploy', false));

    // Started again: the new deployment is live, nothing says stopped.
    $this->actingAs($member)->post(route('services.lifecycle.start', $world->serviceArgs()))->assertRedirect();
    Projects::report($world->server);
    Projects::report($world->server);
    $list = $this->actingAs($member)->getJson(route('deployments.service', $world->serviceArgs()))->assertOk();
    expect($list->json('active.id'))->not->toBe($active->public_id)
        ->and($list->json('active.stopped'))->toBeNull()
        ->and(collect($list->json('deployments'))->pluck('stopped')->filter()->all())->toBe([]);
});
