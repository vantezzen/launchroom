<?php

declare(strict_types=1);

use App\Data\Protocol\V1\Tasks\CleanupSpec;
use App\Data\Protocol\V1\Tasks\InventorySpec;
use App\Enums\DaemonTaskStatus;
use App\Enums\ServerStatus;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\Team;
use App\Services\Tasks\DaemonTasks;
use App\Services\Tasks\StalePendingTasks;
use Illuminate\Support\Facades\Date;
use Tests\Support\Projects;

/*
 * Tasks pinned to a server that stays offline end with the reason instead
 * of waiting forever (G28).
 */

afterEach(fn () => Date::setTestNow());

function staleTask(Server $server, bool $cleanup = false): DaemonTask
{
    $spec = $cleanup ? new CleanupSpec : new InventorySpec('127.0.0.1:5000/library/nginx@sha256:'.str_repeat('a', 64), 'linux/arm64');

    return resolve(DaemonTasks::class)->create($server->team_id, $spec, 900, pin: $server);
}

test('a task waiting for an offline server past its limit is cancelled with the reason', function (): void {
    [$server] = Projects::server(Team::factory()->create());
    $server->forceFill(['status' => ServerStatus::Offline, 'name' => 'server-2'])->save();
    $inventory = staleTask($server);
    $cleanup = staleTask($server, cleanup: true);

    Date::setTestNow(now()->addMinutes(45));
    expect(resolve(StalePendingTasks::class)->cancel())->toBe(1)
        ->and($cleanup->refresh()->status)->toBe(DaemonTaskStatus::Cancelled)
        ->and($cleanup->error)->toBe('server-2 was offline for over 30 minutes, so this task didn’t run.')
        ->and($inventory->refresh()->status)->toBe(DaemonTaskStatus::Pending);

    Date::setTestNow(now()->addHours(6));
    expect(resolve(StalePendingTasks::class)->cancel())->toBe(1)
        ->and($inventory->refresh()->error)->toBe('server-2 was offline for over 6 hours, so this task didn’t run.');
});

test('an online server\'s tasks are left alone', function (): void {
    [$server] = Projects::server(Team::factory()->create());
    $task = staleTask($server);

    Date::setTestNow(now()->addDays(2));
    expect(resolve(StalePendingTasks::class)->cancel())->toBe(0)
        ->and($task->refresh()->status)->toBe(DaemonTaskStatus::Pending);
});
