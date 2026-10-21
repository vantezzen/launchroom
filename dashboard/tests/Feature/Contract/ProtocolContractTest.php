<?php

declare(strict_types=1);

use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\BroadcastAuth;
use App\Data\Protocol\V1\BroadcastAuthRequest;
use App\Data\Protocol\V1\DaemonConfig;
use App\Data\Protocol\V1\EnrollRequest;
use App\Data\Protocol\V1\EnrollResponse;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\LogBatch;
use App\Data\Protocol\V1\NetworkSpec;
use App\Data\Protocol\V1\NodeState;
use App\Data\Protocol\V1\NudgeConfig;
use App\Data\Protocol\V1\PollIntervals;
use App\Data\Protocol\V1\RegistryInfo;
use App\Data\Protocol\V1\Report;
use App\Data\Protocol\V1\RouteSpec;
use App\Data\Protocol\V1\SystemContainer;
use App\Data\Protocol\V1\SystemSpec;
use App\Data\Protocol\V1\TailscaleSpec;
use App\Data\Protocol\V1\Task;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Protocol\V1\TaskHeartbeat;
use App\Data\Protocol\V1\TaskHeartbeatResult;
use App\Data\Protocol\V1\TaskLease;
use App\Data\Protocol\V1\VolumeSpec;
use App\Data\Protocol\V1\WorkloadSpec;
use App\Enums\DaemonTaskType;
use Carbon\CarbonImmutable;
use Tests\Support\ProtocolSchemas;

/*
| Contract tests (docs/10 §2.4): PHP-rendered documents validate against the
| Go-generated schemas, and the Go fixtures decode into the PHP Data objects.
*/

function protocolFixture(string $name): string
{
    return (string) file_get_contents(ProtocolSchemas::root()."/fixtures/v1/{$name}.json");
}

test('every Go fixture validates against its schema', function (): void {
    foreach (glob(ProtocolSchemas::root().'/fixtures/v1/*.json') ?: [] as $path) {
        $schema = explode('.', basename($path))[0];

        expect(ProtocolSchemas::errors($schema, (string) file_get_contents($path)))->toBe([], basename($path));
    }
});

test('rendered NodeStates are schema-valid with and without system containers', function (): void {
    $digest = '@sha256:'.str_repeat('a', 64);

    foreach ([true, false] as $enabled) {
        $state = new NodeState(
            serverId: 'srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK',
            version: 7,
            issuedAt: CarbonImmutable::parse('2026-10-17T12:00:00Z'),
            system: new SystemSpec(
                caddy: $enabled ? new SystemContainer(true, 'ghcr.io/vantezzen/launchroom-caddy'.$digest) : new SystemContainer(false),
                buildkit: new SystemContainer(false),
                tailscale: new TailscaleSpec(false),
                registryForwardTo: $enabled ? '100.64.0.1:5000' : null,
            ),
        );

        expect(ProtocolSchemas::errors('node-state', ProtocolJson::encode($state)))->toBe([]);
    }
});

test('rendered enroll responses, leases and heartbeat results are schema-valid', function (): void {
    $enroll = new EnrollResponse('srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK', 'active', ['cp-1' => 'ed25519:Kay64UG8yvCyLhqU000LxzYeUm0L/hLIl5S8kyKWbdc='], new RegistryInfo('127.0.0.1:5000'), new PollIntervals('30s', '10s'));
    $lease = new TaskLease([new Task('tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P0', DaemonTaskType::Logs, CarbonImmutable::now(), 1, CarbonImmutable::now()->addHour(), (object) ['workload' => 'wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7', 'tail' => 0, 'follow' => true])]);
    $heartbeat = new TaskHeartbeatResult(CarbonImmutable::now(), false);

    expect(ProtocolSchemas::errors('enroll-response', ProtocolJson::encode($enroll)))->toBe([])
        ->and(ProtocolSchemas::errors('task-lease', ProtocolJson::encode($lease)))->toBe([])
        ->and(ProtocolSchemas::errors('task-lease', ProtocolJson::encode(new TaskLease([]))))->toBe([])
        ->and(ProtocolSchemas::errors('task-heartbeat-result', ProtocolJson::encode($heartbeat)))->toBe([]);
});

test('an empty control-plane key set still encodes as an object', function (): void {
    $json = ProtocolJson::encode(new EnrollResponse('srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK', 'active', [], new RegistryInfo('127.0.0.1:5000'), new PollIntervals('30s', '10s')));

    expect($json)->toContain('"controlPlaneKeys":{}');
});

test('Go request fixtures decode into the PHP Data objects', function (): void {
    $enroll = EnrollRequest::validateAndCreate(json_decode(protocolFixture('enroll-request'), true));
    $report = Report::validateAndCreate(json_decode(protocolFixture('report'), true));

    expect($enroll->host->hostname)->toBe('web-1')
        ->and($enroll->host->memoryMb)->toBe(8192)
        ->and($report->workloads)->toHaveCount(2)
        ->and($report->workloads[0]->phase)->toBe('healthy');
});

test('the realtime nudge fixtures decode into and render from the PHP Data objects', function (): void {
    $request = BroadcastAuthRequest::validateAndCreate(json_decode(protocolFixture('broadcast-auth-request'), true));
    $config = json_decode(protocolFixture('daemon-config'), true);
    $auth = json_decode(protocolFixture('broadcast-auth'), true);

    expect($request->channel)->toBe('private-daemon.srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK')
        ->and(json_decode(ProtocolJson::encode(new DaemonConfig(NudgeConfig::from($config['nudge']))), true))->toEqual($config)
        ->and(json_decode(ProtocolJson::encode(new BroadcastAuth($auth['auth'])), true))->toEqual($auth)
        ->and(ProtocolSchemas::errors('daemon-config', ProtocolJson::encode(new DaemonConfig)))->toBe([]);
});

test('Go response fixtures round-trip through the PHP Data objects', function (): void {
    $response = json_decode(protocolFixture('enroll-response'), true);
    $enroll = new EnrollResponse(
        $response['serverId'],
        $response['status'],
        $response['controlPlaneKeys'],
        RegistryInfo::from($response['registry']),
        PollIntervals::from($response['pollIntervals']),
    );

    expect(json_decode(ProtocolJson::encode($enroll), true))->toEqual($response);

    $lease = json_decode(protocolFixture('task-lease.build'), false);
    $tasks = array_map(static fn (object $task): Task => new Task(
        $task->id,
        DaemonTaskType::from($task->type),
        CarbonImmutable::parse($task->leaseExpiresAt),
        $task->attempt,
        CarbonImmutable::parse($task->deadline),
        $task->spec,
    ), $lease->tasks);

    expect(json_decode(ProtocolJson::encode(new TaskLease($tasks)), true))->toEqual(json_decode(protocolFixture('task-lease.build'), true));

    $state = json_decode(protocolFixture('node-state.web-postgres'), false);
    $system = $state->system;
    $node = new NodeState(
        $state->serverId,
        $state->version,
        CarbonImmutable::parse($state->issuedAt),
        new SystemSpec(
            new SystemContainer($system->caddy->enabled, $system->caddy->image ?? null),
            new SystemContainer($system->buildkit->enabled, $system->buildkit->image ?? null),
            new TailscaleSpec($system->tailscale->enabled),
            $system->registry->forwardTo ?? null,
        ),
        array_map(NetworkSpec::fromProtocol(...), $state->networks),
        array_map(VolumeSpec::fromProtocol(...), $state->volumes),
        array_map(WorkloadSpec::fromProtocol(...), $state->workloads),
        $state->ambassadors,
        array_map(RouteSpec::fromProtocol(...), $state->routes),
        $state->firewall->meshAllow,
    );

    $encoded = ProtocolJson::encode($node);

    expect(ProtocolSchemas::errors('node-state', $encoded))->toBe([])
        ->and(json_decode($encoded, true))->toEqual(json_decode(protocolFixture('node-state.web-postgres'), true));
});

test('daemon request bodies the PHP side accepts are schema-valid', function (): void {
    $documents = [
        'lease-request' => ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 2, 'types' => ['build']],
        'task-heartbeat' => ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskHeartbeat', 'attempt' => 1],
        'task-completion' => ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => 1, 'outcome' => 'succeeded', 'build' => ['image' => '127.0.0.1:5000/apps/svc@sha256:'.str_repeat('b', 64), 'durationMs' => 5]],
        'log-batch' => ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LogBatch', 'chunks' => [['source' => ['kind' => 'task', 'id' => 'tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P0'], 'seq' => 1, 'lines' => [['time' => '2026-10-17T12:00:00Z', 'stream' => 'stdout', 'text' => 'x']]]]],
    ];
    $classes = ['lease-request' => LeaseRequest::class, 'task-heartbeat' => TaskHeartbeat::class, 'task-completion' => TaskCompletion::class, 'log-batch' => LogBatch::class];

    foreach ($documents as $schema => $document) {
        $classes[$schema]::validateAndCreate($document);

        expect(ProtocolSchemas::errors($schema, (string) json_encode($document)))->toBe([], $schema);
    }
});
