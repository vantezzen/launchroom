<?php

declare(strict_types=1);

use App\Enums\DaemonTaskType;
use App\Models\DaemonTask;
use App\Models\LogChunk;
use App\Models\Server;
use Tests\Support\FakeDaemon;
use Tests\Support\ProtocolSchemas;

beforeEach(function (): void {
    $seed = random_bytes(32);
    $this->server = Server::factory()->withSigningSeed($seed)->online()->create(['desired_version' => 5]);
    $this->daemon = new FakeDaemon($seed)->as($this->server->public_id);
});

function reportBody(Server $server, int $seq, bool $full = false, int $applied = 3): array
{
    return [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'Report', 'serverId' => $server->public_id,
        'observedAt' => '2026-10-17T12:00:05Z', 'seq' => $seq, 'full' => $full, 'appliedVersion' => $applied, 'workloads' => [],
    ];
}

test('reports update the observed version and ignore stale deltas', function (): void {
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', reportBody($this->server, 2))->assertNoContent();
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', reportBody($this->server, 1, applied: 1))->assertNoContent();

    expect($this->server->refresh()->observed_version)->toBe(3)
        ->and($this->server->last_report_seq)->toBe(2);

    // After a restart the daemon sends a full report with a fresh sequence.
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', reportBody($this->server, 1, true, 4))->assertNoContent();

    expect($this->server->refresh()->observed_version)->toBe(4);
});

test('the Go report fixture is accepted', function (): void {
    $fixture = json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/v1/report.json'), true);
    $fixture['serverId'] = $this->server->public_id;

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', $fixture)->assertNoContent();
});

test('a server cannot report on another server', function (): void {
    $other = Server::factory()->online()->create();

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', reportBody($other, 1))->assertForbidden();
});

test('log chunks for held tasks are stored once', function (): void {
    $task = DaemonTask::query()->create(['team_id' => $this->server->team_id, 'type' => DaemonTaskType::Logs, 'spec' => (object) []]);
    $task->forceFill(['server_id' => $this->server->id])->save();

    $batch = ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LogBatch', 'chunks' => [[
        'source' => ['kind' => 'task', 'id' => $task->public_id],
        'seq' => 1,
        'lines' => [['time' => '2026-10-17T12:00:00Z', 'stream' => 'stdout', 'text' => 'hello']],
    ]]];

    $this->daemon->send($this, 'POST', '/daemon/v1/logs', $batch)->assertNoContent();
    $this->daemon->send($this, 'POST', '/daemon/v1/logs', $batch)->assertNoContent();

    expect(LogChunk::query()->count())->toBe(1)
        ->and(LogChunk::query()->sole()->lines[0]['text'])->toBe('hello');
});

test('logs for tasks of other servers are refused', function (): void {
    $other = Server::factory()->online()->create(['team_id' => $this->server->team_id]);
    $task = DaemonTask::query()->create(['team_id' => $other->team_id, 'type' => DaemonTaskType::Logs, 'spec' => (object) []]);
    $task->forceFill(['server_id' => $other->id])->save();

    $this->daemon->send($this, 'POST', '/daemon/v1/logs', ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LogBatch', 'chunks' => [[
        'source' => ['kind' => 'task', 'id' => $task->public_id], 'seq' => 1,
        'lines' => [['time' => '2026-10-17T12:00:00Z', 'stream' => 'stdout', 'text' => 'x']],
    ]]])->assertForbidden();

    expect(LogChunk::query()->count())->toBe(0);
});

test('reports with nanosecond times, microsecond sequences and newer optional fields are accepted', function (): void {
    $body = [
        ...reportBody($this->server, 1792238400123456, true),
        'observedAt' => '2026-10-17T12:00:05.123456789Z',
        'workloads' => [[
            'id' => 'wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7', 'phase' => 'running',
            'observedAt' => '2026-10-17T12:00:05.5+02:00', 'restartCount' => 0,
        ]],
    ];

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', $body)->assertNoContent();

    expect($this->server->refresh()->last_report_seq)->toBe(1792238400123456);
});
