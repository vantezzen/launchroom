<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Data\Audit\AuditActor;
use App\Enums\HoldKind;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Services\Holds\Holds;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

test('a deployment-blocking hold change moves the held deployments on after commit; a move hold does not', function (): void {
    $team = Team::factory()->create();
    Projects::server($team);
    $project = Projects::create($team, 'shop');
    $web = Projects::image($project, 'web');
    $production = $project->production()->sole();
    $instance = ServiceInstance::query()->where('environment_id', $production->id)->where('service_id', $web->id)->sole();
    Queue::fake([AdvanceDeploymentJob::class]);
    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
    $deployment = Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail();
    Queue::fake([AdvanceDeploymentJob::class]);

    Holds::changed(HoldKind::Move, [$instance->id]);
    Queue::assertNotPushed(AdvanceDeploymentJob::class);

    DB::transaction(function () use ($instance): void {
        Holds::changed(HoldKind::Restore, [$instance->id]);
        Queue::assertNotPushed(AdvanceDeploymentJob::class);
    });

    Queue::assertPushed(AdvanceDeploymentJob::class, static fn (AdvanceDeploymentJob $job): bool => $job->deploymentId === $deployment->id);
});
