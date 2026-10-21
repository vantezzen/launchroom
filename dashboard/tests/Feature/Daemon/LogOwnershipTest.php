<?php

declare(strict_types=1);

use App\Actions\Daemons\StoreLogBatch;
use App\Data\Protocol\V1\LogBatch;
use App\Enums\LogSourceKind;
use App\Enums\VariableType;
use App\Models\LogChunk;
use App\Models\Placement;
use App\Models\Server;
use App\Queries\DeploymentLogs;
use App\Services\Deployments\TaskReleaseRunner;
use Tests\Support\DeploymentUi;
use Tests\Support\FakeDaemon;
use Tests\Support\Projects;

/**
 * L1 / M2 (2026-10 security review): servers only write logs about what they
 * run, pending servers write nothing, and stored lines are masked against
 * the deployment's secrets.
 */
beforeEach(fn () => Projects::useTestKek());

/**
 * @param  list<string>  $texts
 */
function logBatch(LogSourceKind $kind, string $id, array $texts, int $seq = 1): LogBatch
{
    return LogBatch::validateAndCreate(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LogBatch', 'chunks' => [[
        'source' => ['kind' => $kind->value, 'id' => $id],
        'seq' => $seq,
        'lines' => array_map(static fn (string $text): array => ['time' => '2026-10-18T12:00:00Z', 'stream' => 'stdout', 'text' => $text], $texts),
    ]]]);
}

test('workload and deployment logs are stored only from the server that runs them', function (): void {
    $world = DeploymentUi::make();
    $deployment = $world->latest($world->web);
    $placement = Placement::query()->where('deployment_id', $deployment->id)->firstOrFail();
    [$neighbour] = Projects::server($world->team, ip: '203.0.113.8');
    $store = resolve(StoreLogBatch::class);

    // A server of the same team without that workload or deployment.
    $store->handle($neighbour, logBatch(LogSourceKind::Workload, $placement->workload_id, ['deploy succeeded, log in at https://evil.example']));
    $store->handle($neighbour, logBatch(LogSourceKind::Deployment, $deployment->public_id, ['fake rollout line']));
    expect(LogChunk::query()->where('server_id', $neighbour->id)->count())->toBe(0);

    // The server that holds the placement.
    $store->handle($world->server, logBatch(LogSourceKind::Workload, $placement->workload_id, ['listening on :80']));
    $store->handle($world->server, logBatch(LogSourceKind::Deployment, $deployment->public_id, ['rolled out'], 2));

    $texts = collect(resolve(DeploymentLogs::class)->page($deployment)->lines)->pluck('text')->all();
    expect($texts)->toContain('listening on :80', 'rolled out')
        ->and(LogChunk::query()->where('server_id', $world->server->id)->whereNotNull('deployment_id')->count())->toBe(2);
});

test('deployment logs never show chunks another server wrote for that workload or deployment', function (): void {
    $world = DeploymentUi::make();
    $deployment = $world->latest($world->web);
    $placement = Placement::query()->where('deployment_id', $deployment->id)->firstOrFail();
    [$neighbour] = Projects::server($world->team, ip: '203.0.113.9');

    // Rows written before the store-side check existed.
    foreach ([[LogSourceKind::Workload, $placement->workload_id], [LogSourceKind::Deployment, $deployment->public_id]] as $i => [$kind, $id]) {
        LogChunk::query()->create([
            'team_id' => $world->team->id, 'server_id' => $neighbour->id, 'source_kind' => $kind, 'source_id' => $id, 'seq' => $i + 1,
            'lines' => [['time' => '2026-10-18T12:00:00Z', 'stream' => 'stdout', 'text' => 'injected']], 'created_at' => now(),
        ]);
    }

    $world->logs($deployment, ['genuine']);

    $texts = collect(resolve(DeploymentLogs::class)->page($deployment)->lines)->pluck('text')->all();
    expect($texts)->toBe(['genuine']);
});

test('stored deployment lines are masked against the deployment secrets and the values that embed them', function (): void {
    $world = DeploymentUi::make(deploy: false);
    $world->variable('API_TOKEN', 'tok_live_9f8e7d6c5b4a', VariableType::Secret);
    $world->variable('API_URL', 'https://bot:${{API_TOKEN}}@api.example.com');
    $world->variable('APP_NAME', 'shop-frontend');
    $world->deploy();
    $deployment = $world->latest($world->web);
    $placement = Placement::query()->where('deployment_id', $deployment->id)->firstOrFail();

    resolve(StoreLogBatch::class)->handle($world->server, logBatch(LogSourceKind::Workload, $placement->workload_id, [
        'token tok_live_9f8e7d6c5b4a',
        'b64 '.base64_encode('tok_live_9f8e7d6c5b4a'),
        'calling https://bot:tok_live_9f8e7d6c5b4a@api.example.com/v1',
        'booting shop-frontend',
    ]));

    $stored = json_encode(LogChunk::query()->sole()->lines, JSON_THROW_ON_ERROR);
    $texts = collect(resolve(DeploymentLogs::class)->page($deployment)->lines)->pluck('text')->all();

    expect($stored)->not->toContain('tok_live_9f8e7d6c5b4a')
        ->not->toContain(base64_encode('tok_live_9f8e7d6c5b4a'))
        ->and($texts)->toBe(['token ***', 'b64 ***', 'calling ***/v1', 'booting shop-frontend']);
});

test('release commands tell the daemon which env values to mask', function (): void {
    $world = DeploymentUi::make(deploy: false);
    $world->variable('API_TOKEN', 'tok_live_9f8e7d6c5b4a', VariableType::Secret);
    $world->variable('API_URL', 'https://bot:${{API_TOKEN}}@api.example.com');
    $world->deploy();
    $deployment = $world->latest($world->web);

    $task = (fn () => $this->create($deployment, ['php', 'artisan', 'migrate'], 'release'))->call(resolve(TaskReleaseRunner::class));

    expect($task->spec->maskEnv)->toBe(['API_TOKEN', 'API_URL', 'DATABASE_URL']);
});

test('pending servers cannot post logs or reports', function (): void {
    $seed = random_bytes(32);
    $server = Server::factory()->withSigningSeed($seed)->create();
    $daemon = new FakeDaemon($seed)->as($server->public_id);

    $daemon->send($this, 'POST', '/daemon/v1/logs', ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LogBatch', 'chunks' => []])->assertForbidden();
    $daemon->send($this, 'POST', '/daemon/v1/reports', [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'Report', 'serverId' => $server->public_id,
        'observedAt' => '2026-10-17T12:00:05Z', 'seq' => 1, 'full' => true, 'appliedVersion' => 0, 'workloads' => [],
    ])->assertForbidden();

    expect($server->refresh()->last_report_at)->toBeNull();
});
