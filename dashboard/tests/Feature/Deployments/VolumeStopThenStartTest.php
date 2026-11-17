<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Deployments\RedeployService;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\DeploymentStatus;
use App\Enums\WorkloadRole;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\ServiceInstance;
use Tests\Support\Projects;
use Tests\Support\VolumeServices;

/*
 * No two copies on one volume (docs/04 §8, G23): a service that keeps data
 * in a volume stops its old version before the new one starts, like a
 * database, unless it says it handles two copies.
 */

beforeEach(fn () => Projects::useTestKek());

function volumeLatest(ServiceInstance $instance): Deployment
{
    return Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail();
}

test('a redeploy stops the old version before the new one starts', function (): void {
    [, $server, , $instance] = VolumeServices::runningApp();
    $old = $instance->active_deployment_id;

    $deployment = resolve(RedeployService::class)->handle($instance, null, null, AuditActor::system());

    // The old replica drains at once and nothing new runs yet.
    expect($deployment->refresh()->status)->toBe(DeploymentStatus::RollingOut)
        ->and($deployment->placements()->count())->toBe(0)
        ->and(Placement::query()->where('deployment_id', $old)->sole()->role)->toBe(WorkloadRole::Draining)
        ->and(collect(Projects::nodeState($server)['workloads'])->pluck('deployment')->all())->toBe([Deployment::query()->findOrFail($old)->public_id]);

    // Once the server reports it stopped, the new one is placed.
    Projects::report($server);
    expect($deployment->refresh()->placements()->count())->toBe(1);

    Projects::report($server);
    Projects::report($server);
    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Active);
});

test('a failed rollout starts the stopped version again', function (): void {
    [, $server, , $instance] = VolumeServices::runningApp();
    $old = $instance->active_deployment_id;

    $deployment = resolve(RedeployService::class)->handle($instance, null, null, AuditActor::system());
    Projects::report($server);
    $placement = $deployment->refresh()->placements()->sole();

    Projects::report($server, phases: [$placement->workload_id => 'failed'], messages: [$placement->workload_id => 'create failed']);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and(Placement::query()->where('deployment_id', $old)->sole()->role)->toBe(WorkloadRole::Serving);
});

test('a service that handles two copies on its volume overlaps as before', function (): void {
    [, $server, , $instance] = VolumeServices::runningApp();
    $old = $instance->active_deployment_id;
    resolve(UpdateServiceSettings::class)->handle($instance->refresh(), UpdateServiceSettingsData::from(['deploy' => [...$instance->deploy->toArray(), 'overlapOnVolumes' => true]]));
    resolve(DeployEnvironment::class)->handle($instance->environment, null, AuditActor::system());

    $deployment = volumeLatest($instance);
    expect($deployment->placements()->count())->toBe(1)
        ->and(Placement::query()->where('deployment_id', $old)->sole()->role)->toBe(WorkloadRole::Serving);
});

test('staging never runs two copies of a service with a volume', function (): void {
    [, , , $instance] = VolumeServices::runningApp();
    resolve(UpdateServiceSettings::class)->handle($instance->refresh(), UpdateServiceSettingsData::from(['deploy' => [...$instance->deploy->toArray(), 'drainSeconds' => 20]]));
    resolve(DeployEnvironment::class)->handle($instance->environment, null, AuditActor::system(), holdTraffic: true);

    expect(volumeLatest($instance)->hold_traffic)->toBeFalse();
});
