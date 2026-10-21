<?php

declare(strict_types=1);

use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Models\DaemonTask;
use App\Models\Server;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeDaemon;
use Tests\Support\ProtocolSchemas;

/*
| A launchroomd restart hands running tasks back ("released", docs/03 §7):
| they are retried, not failed or cancelled, without using up an attempt.
*/

beforeEach(function (): void {
    $seed = random_bytes(32);
    $this->server = Server::factory()->withSigningSeed($seed)->online()->create(['roles' => [ServerRole::Runtime]]);
    $this->daemon = new FakeDaemon($seed)->as($this->server->public_id);
    $this->task = DaemonTask::query()->create([
        'team_id' => $this->server->team_id, 'type' => DaemonTaskType::Logs, 'max_attempts' => 1,
        'spec' => (object) ['workload' => 'wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7', 'tail' => 100, 'follow' => false],
    ]);
    $this->lease = fn (): TestResponse => $this->daemon->send($this, 'POST', '/daemon/v1/tasks/lease', ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => ['logs']]);
    $this->release = fn (int $attempt): TestResponse => $this->daemon->send($this, 'POST', "/daemon/v1/tasks/{$this->task->public_id}/complete",
        ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => $attempt, 'outcome' => 'released', 'error' => 'launchroomd stopped before the task finished: context canceled'],
        ['Idempotency-Key' => "{$this->task->public_id}:{$attempt}"]);
});

test('the protocol fixture is schema-valid', function (): void {
    expect(ProtocolSchemas::errors('task-completion', (string) file_get_contents(ProtocolSchemas::root().'/fixtures/v1/task-completion.released.json')))->toBe([]);
});

test('a released task is requeued at once and keeps its attempt budget', function (): void {
    ($this->lease)();
    ($this->release)(1)->assertNoContent();

    $task = $this->task->refresh();
    expect($task->status)->toBe(DaemonTaskStatus::Pending)
        ->and($task->server_id)->toBeNull()
        ->and($task->max_attempts)->toBe(2);

    // Leased again (attempt 2) and completed normally.
    expect(($this->lease)()->json('tasks.0.attempt'))->toBe(2);
    $this->daemon->send($this, 'POST', "/daemon/v1/tasks/{$this->task->public_id}/complete",
        ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => 2, 'outcome' => 'succeeded'],
        ['Idempotency-Key' => "{$this->task->public_id}:2"])->assertNoContent();

    expect($this->task->refresh()->status)->toBe(DaemonTaskStatus::Succeeded);
});

test('a released task past its deadline fails instead', function (): void {
    ($this->lease)();
    $this->task->refresh()->forceFill(['deadline_at' => now()->subSecond()])->save();

    ($this->release)(1)->assertNoContent();

    expect($this->task->refresh()->status)->toBe(DaemonTaskStatus::Failed)
        ->and($this->task->error)->toContain('launchroomd stopped');
});
