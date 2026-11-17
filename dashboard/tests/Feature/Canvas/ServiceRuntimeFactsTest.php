<?php

declare(strict_types=1);

use App\Data\Canvas\ServiceRuntimeFactsData;
use App\Data\Deployments\ContainerExitData;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Queries\ServiceRuntimeFacts;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/*
 * The service panel's runtime facts (G7): restarts of the live version and
 * its last exit with the reason in words.
 */

beforeEach(function (): void {
    Projects::useTestKek();
});

function factsOf(DeploymentUi $world): ?ServiceRuntimeFactsData
{
    return resolve(ServiceRuntimeFacts::class)->for(ServiceInstance::query()->where('service_id', $world->web->id)->where('environment_id', $world->production->id)->sole());
}

test('a live version that never restarted has no facts', function (): void {
    expect(factsOf(DeploymentUi::make()))->toBeNull();
});

test('restarts and an out of memory exit are told in words', function (): void {
    $world = DeploymentUi::make();
    Placement::query()->where('deployment_id', $world->latest($world->web)->id)->firstOrFail()->forceFill([
        'restart_count' => 3,
        'last_exit' => new ContainerExitData(at: now()->subHour()->toIso8601String(), code: 137, oomKilled: true),
    ])->save();

    $facts = factsOf($world);

    expect($facts?->restarts)->toBe(3)
        ->and($facts?->restartText)->toBe('Restarted 3 times since this version went live (out of memory)')
        ->and($facts?->lastExit)->toBe('Exited with code 137: it ran out of memory.')
        ->and($facts?->lastExitWhere)->toBeNull()
        ->and($facts?->advice)->not->toBeNull();
});

test('137 without the out of memory flag is not called out of memory', function (bool $byLaunchroom, string $text): void {
    $world = DeploymentUi::make();
    Placement::query()->where('deployment_id', $world->latest($world->web)->id)->firstOrFail()->forceFill([
        'restart_count' => 1,
        'last_exit' => new ContainerExitData(at: now()->toIso8601String(), code: 137, signal: 9, byLaunchroom: $byLaunchroom),
    ])->save();

    expect(factsOf($world)?->restartText)->toBe($text);
})->with([
    'killed' => [false, 'Restarted once since this version went live (killed: SIGKILL)'],
    'by launchroom' => [true, 'Restarted once since this version went live (stopped by launchroom after its stop timeout)'],
]);

test('a clean exit without restarts says nothing', function (): void {
    $world = DeploymentUi::make();
    Placement::query()->where('deployment_id', $world->latest($world->web)->id)->firstOrFail()->forceFill([
        'last_exit' => new ContainerExitData(at: now()->toIso8601String(), code: 0),
    ])->save();

    expect(factsOf($world))->toBeNull();
});

test('crashes in the last 24 hours are counted even when the replicas show no restarts', function (): void {
    $world = DeploymentUi::make();
    $placement = Placement::query()->where('deployment_id', $world->latest($world->web)->id)->firstOrFail();
    $row = ['team_id' => $placement->team_id, 'service_instance_id' => $placement->service_instance_id, 'deployment_id' => $placement->deployment_id, 'server_id' => $world->server->id, 'workload_id' => $placement->workload_id];
    DB::table('service_crashes')->insert([
        [...$row, 'at' => now()->subDays(2), 'exit_code' => 1, 'oom_killed' => false],
        [...$row, 'at' => now()->subHours(5), 'exit_code' => 1, 'oom_killed' => false],
        [...$row, 'at' => now()->subHour(), 'exit_code' => 137, 'oom_killed' => true],
    ]);

    $facts = factsOf($world);

    expect($facts?->recentCrashes)->toBe('Crashed 2 times in the last 24 hours (out of memory)')
        ->and($facts?->restartText)->toBeNull();
});
