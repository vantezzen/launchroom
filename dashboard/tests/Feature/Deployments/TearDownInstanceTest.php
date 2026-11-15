<?php

declare(strict_types=1);

use App\Actions\Deployments\TearDownInstance;
use App\Enums\DeploymentStatus;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
});

test('removing a service while its deployment routes traffic cancels it instead of rolling the teardown back', function (): void {
    $world = DeploymentUi::make();
    $live = $world->latest($world->web);
    $routing = $live->replicate(['public_id']);
    $routing->forceFill(['public_id' => PublicId::generate('dpl'), 'status' => DeploymentStatus::Routing, 'finished_at' => null, 'went_live_at' => null])->save();
    $instance = ServiceInstance::query()->whereKey($live->service_instance_id)->sole();

    $servers = DB::transaction(fn (): array => resolve(TearDownInstance::class)->handle($instance));

    expect($routing->refresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($live->refresh()->status)->toBe(DeploymentStatus::Superseded)
        ->and($servers)->toBe([$world->server->id])
        ->and(Placement::query()->where('service_instance_id', $instance->id)->exists())->toBeFalse()
        ->and($instance->refresh()->active_deployment_id)->toBeNull();
});
