<?php

declare(strict_types=1);

use App\Data\Deployments\ContainerExitData;
use App\Enums\DaemonTaskPurpose;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Enums\WorkloadPhase;
use App\Models\DaemonTask;
use App\Models\Placement;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/*
 * Live logs and replicas that aren't running (G9): an exited replica still
 * gets a tail (launchroomd ships its history and waits for the next start),
 * and every replica without one says why.
 */

beforeEach(function (): void {
    Projects::useTestKek();
});

function tailWebPlacement(DeploymentUi $world): Placement
{
    return Placement::query()->where('deployment_id', $world->latest($world->web)->id)->firstOrFail();
}

test('an exited replica gets a tail and a note with its exit in words', function (): void {
    $world = DeploymentUi::make();
    tailWebPlacement($world)->forceFill([
        'phase' => WorkloadPhase::Exited,
        'last_exit' => new ContainerExitData(at: now()->toIso8601String(), code: 137, oomKilled: true),
    ])->save();
    $viewer = $world->member(TeamRole::Viewer);

    $response = $this->actingAs($viewer)->postJson(route('projects.logs.tail', $world->envArgs()), ['service' => $world->web->public_id])->assertOk();

    expect($response->json('following'))->toBe(1)
        ->and($response->json('notes'))->toBe(['Replica 1 of web isn’t running. Exited with code 137: it ran out of memory. Its last lines are shown, and live logs go on when it starts again.'])
        ->and(DaemonTask::query()->where('purpose', DaemonTaskPurpose::LogTail->value)->count())->toBe(1);
});

test('a replica on an offline server gets no tail and says why', function (): void {
    $world = DeploymentUi::make();
    $world->server->forceFill(['status' => ServerStatus::Offline])->save();
    $viewer = $world->member(TeamRole::Viewer);

    $response = $this->actingAs($viewer)->postJson(route('projects.logs.tail', $world->envArgs()), ['service' => $world->web->public_id])->assertOk();

    expect($response->json('following'))->toBe(0)
        ->and($response->json('notes'))->toBe(["{$world->server->name} is offline, so replica 1 of web can’t show live logs."])
        ->and(DaemonTask::query()->where('purpose', DaemonTaskPurpose::LogTail->value)->count())->toBe(0);
});

test('a replica that never started gets no tail and points to its deployment', function (): void {
    $world = DeploymentUi::make();
    tailWebPlacement($world)->forceFill(['phase' => WorkloadPhase::Failed])->save();

    $response = $this->actingAs($world->member(TeamRole::Viewer))->postJson(route('projects.logs.tail', $world->envArgs()), ['service' => $world->web->public_id])->assertOk();

    expect($response->json('following'))->toBe(0)
        ->and($response->json('notes'))->toBe(['Replica 1 of web couldn’t start, so it has no logs. Its deployment says why.']);
});
