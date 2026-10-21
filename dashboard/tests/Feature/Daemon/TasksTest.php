<?php

declare(strict_types=1);

use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Services\Crypto\ResponseSignature;
use App\Services\Crypto\Signer;
use Tests\Support\FakeDaemon;
use Tests\Support\ProtocolSchemas;

function logsTask(Server $server, array $attributes = []): DaemonTask
{
    return DaemonTask::query()->create([
        'team_id' => $server->team_id,
        'type' => DaemonTaskType::Logs,
        'spec' => (object) ['workload' => 'wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7', 'tail' => 100, 'follow' => false],
        ...$attributes,
    ]);
}

function leaseBody(array $types = ['logs', 'build'], int $max = 4): array
{
    return ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => $max, 'types' => $types];
}

beforeEach(function (): void {
    $seed = random_bytes(32);
    $this->server = Server::factory()->withSigningSeed($seed)->online()->create(['roles' => [ServerRole::Runtime]]);
    $this->daemon = new FakeDaemon($seed)->as($this->server->public_id);
});

test('leasing returns a signed, schema-valid lease and marks tasks leased', function (): void {
    $task = logsTask($this->server);

    $response = $this->daemon->send($this, 'POST', '/daemon/v1/tasks/lease', leaseBody())->assertOk();
    $body = (string) $response->getContent();
    $signer = resolve(Signer::class);

    expect(ResponseSignature::verify((string) $response->headers->get('X-LR-Signature'), $body, [$signer->kid => $signer->publicKey()]))->toBeTrue()
        ->and(ProtocolSchemas::errors('task-lease', $body))->toBe([])
        ->and($response->json('tasks.0.id'))->toBe($task->public_id)
        ->and($response->json('tasks.0.attempt'))->toBe(1);

    $task->refresh();
    expect($task->status)->toBe(DaemonTaskStatus::Leased)
        ->and($task->server_id)->toBe($this->server->id);

    // Nothing left to lease.
    expect($this->daemon->send($this, 'POST', '/daemon/v1/tasks/lease', leaseBody())->json('tasks'))->toBe([]);
});

test('an empty lease is still a valid document', function (): void {
    $body = (string) $this->daemon->send($this, 'POST', '/daemon/v1/tasks/lease', leaseBody())->getContent();

    expect(ProtocolSchemas::errors('task-lease', $body))->toBe([])->and($body)->toContain('"tasks":[]');
});

test('servers only lease their own team’s tasks, matching roles and pins', function (): void {
    $otherTeamServer = Server::factory()->online()->create();
    $sameTeamOther = Server::factory()->online()->create(['team_id' => $this->server->team_id]);

    logsTask($otherTeamServer);
    logsTask($this->server, ['required_role' => ServerRole::Build]);
    logsTask($this->server, ['pinned_to_server' => true, 'server_id' => $sameTeamOther->id]);
    $mine = logsTask($this->server, ['pinned_to_server' => true, 'server_id' => $this->server->id]);
    $pool = logsTask($this->server, ['required_role' => ServerRole::Runtime]);

    $ids = collect($this->daemon->send($this, 'POST', '/daemon/v1/tasks/lease', leaseBody())->json('tasks'))->pluck('id')->all();

    expect($ids)->toBe([$mine->public_id, $pool->public_id]);
});

test('only requested task types are leased, up to max', function (): void {
    logsTask($this->server);
    logsTask($this->server);

    expect($this->daemon->send($this, 'POST', '/daemon/v1/tasks/lease', leaseBody(['build']))->json('tasks'))->toBe([])
        ->and($this->daemon->send($this, 'POST', '/daemon/v1/tasks/lease', leaseBody(['logs'], 1))->json('tasks'))->toHaveCount(1);
});

test('heartbeats extend the lease and report cancellation', function (): void {
    $task = logsTask($this->server);
    $this->daemon->send($this, 'POST', '/daemon/v1/tasks/lease', leaseBody());
    $this->travel(30)->seconds();
    $task->forceFill(['cancel_requested_at' => now()])->save();

    $response = $this->daemon->send($this, 'POST', "/daemon/v1/tasks/{$task->public_id}/heartbeat", [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskHeartbeat', 'attempt' => 1, 'progress' => 'tailing',
    ])->assertOk();

    expect(ProtocolSchemas::errors('task-heartbeat-result', (string) $response->getContent()))->toBe([])
        ->and($response->json('cancel'))->toBeTrue()
        ->and($task->refresh()->lease_expires_at?->getTimestamp())->toBe(now()->addSeconds(60)->getTimestamp())
        ->and($task->progress)->toBe('tailing');
});

test('a server cannot touch tasks held by another server', function (): void {
    $other = Server::factory()->online()->create(['team_id' => $this->server->team_id]);
    $task = logsTask($other, ['pinned_to_server' => true, 'server_id' => $other->id]);

    $this->daemon->send($this, 'POST', "/daemon/v1/tasks/{$task->public_id}/heartbeat", [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskHeartbeat', 'attempt' => 1,
    ])->assertNotFound();
});

test('completion is idempotent per attempt and needs a matching idempotency key', function (): void {
    $task = logsTask($this->server);
    $this->daemon->send($this, 'POST', '/daemon/v1/tasks/lease', leaseBody());
    $body = ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => 1, 'outcome' => 'succeeded'];
    $key = ['Idempotency-Key' => "{$task->public_id}:1"];

    $this->daemon->send($this, 'POST', "/daemon/v1/tasks/{$task->public_id}/complete", $body, ['Idempotency-Key' => "{$task->public_id}:2"])->assertUnprocessable();
    $this->daemon->send($this, 'POST', "/daemon/v1/tasks/{$task->public_id}/complete", $body, $key)->assertNoContent();
    $this->daemon->send($this, 'POST', "/daemon/v1/tasks/{$task->public_id}/complete", $body, $key)->assertNoContent();

    expect($task->refresh()->status)->toBe(DaemonTaskStatus::Succeeded)
        ->and($task->completed_attempt)->toBe(1);

    $this->daemon->send($this, 'POST', "/daemon/v1/tasks/{$task->public_id}/complete", [...$body, 'attempt' => 2], ['Idempotency-Key' => "{$task->public_id}:2"])->assertConflict();
});

test('expired leases are requeued for the next attempt, then fail', function (): void {
    $task = logsTask($this->server, ['max_attempts' => 2]);
    $this->daemon->send($this, 'POST', '/daemon/v1/tasks/lease', leaseBody());

    $this->travel(61)->seconds();
    expect($this->daemon->send($this, 'POST', '/daemon/v1/tasks/lease', leaseBody())->json('tasks.0.attempt'))->toBe(2);

    $this->travel(61)->seconds();
    $this->artisan('launchroom:servers:check-liveness')->assertSuccessful();

    expect($task->refresh()->status)->toBe(DaemonTaskStatus::Failed);
});
