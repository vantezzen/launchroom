<?php

declare(strict_types=1);

use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\HealthSpec;
use App\Data\Protocol\V1\NetworkSpec;
use App\Data\Protocol\V1\NodeState;
use App\Data\Protocol\V1\Report;
use App\Data\Protocol\V1\SystemContainer;
use App\Data\Protocol\V1\SystemSpec;
use App\Data\Protocol\V1\TailscaleSpec;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Protocol\V1\VolumeSpec;
use App\Data\Protocol\V1\WatchdogSpec;
use App\Data\Protocol\V1\WorkloadSpec;
use App\Enums\CertFailureKind;
use App\Enums\DaemonFeature;
use App\Enums\EnvAliasSource;
use App\Enums\IngressFailureReason;
use App\Enums\ProbeHint;
use App\Enums\WorkloadEventReason;
use App\Enums\WorkloadFailureCode;
use App\Enums\WorkloadIssueKind;
use App\Models\Server;
use App\Services\NodeStates\FeatureGate;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProtocolSchemas;

/*
| The operations fields of the protocol (runtime visibility, self-recovery,
| control-plane care, framework runtime contract): the Go fixtures decode
| into the PHP mirrors, unknown codes read as none, and the NodeState fields
| render schema-valid and are left out for servers without their feature.
*/

/**
 * @return array<string, mixed>
 */
function opsFixture(string $name): array
{
    return json_decode((string) file_get_contents(ProtocolSchemas::root()."/fixtures/v1/{$name}.json"), true, 64, JSON_THROW_ON_ERROR);
}

/**
 * @param  array<mixed>  $value
 * @return array<mixed>
 */
function opsWithoutNulls(array $value): array
{
    $out = [];

    foreach ($value as $key => $item) {
        if ($item !== null) {
            $out[$key] = is_array($item) ? opsWithoutNulls($item) : $item;
        }
    }

    return $out;
}

test('the operations report decodes into the Report mirror', function (): void {
    $report = Report::validateAndCreate(opsFixture('report.ops'));
    $web = $report->workloads[0];
    $failed = $report->workloads[1];

    expect($web->health->hint)->toBe(ProbeHint::HostNotAllowed)
        ->and($web->health->body)->toContain('DisallowedHost')
        ->and($web->issue->kind)->toBe(WorkloadIssueKind::Unresponsive)
        ->and($web->image->startsAsRoot())->toBeFalse()
        ->and($failed->failure->code)->toBe(WorkloadFailureCode::WrongPlatform)
        ->and($failed->image->startsAsRoot())->toBeTrue()
        ->and($report->events[0]->signal)->toBe(9)
        ->and($report->events[0]->byLaunchroom)->toBeTrue()
        ->and($report->events[1]->reason)->toBe(WorkloadEventReason::Health)
        ->and($report->events[2]->code)->toBe(WorkloadFailureCode::WrongPlatform)
        ->and($report->ingress->reason)->toBe(IngressFailureReason::PortInUse)
        ->and($report->ingress->port)->toBe(80)
        ->and($report->ingress->certificates[0]->failureKind)->toBe(CertFailureKind::RateLimited)
        ->and($report->host->lastRefusal['clockSkewMs'])->toBe(-94000)
        ->and($report->host->daemon['previousExit']['unclean'])->toBeTrue()
        ->and($report->host->health['disk']['volumes'])->toHaveCount(2)
        ->and($report->controlPlane['restarts'][0]['service'])->toBe('dashboard');
});

test('unknown operations codes from a newer launchroomd read as none', function (): void {
    $document = opsFixture('report.ops');
    $document['workloads'][1]['failure']['code'] = 'cgroup_frozen';
    $document['workloads'][0]['health']['hint'] = 'needs_login';
    $document['events'][2]['code'] = 'cgroup_frozen';
    $document['ingress']['reason'] = 'kernel_refused';
    $document['ingress']['certificates'][0]['failureKind'] = 'ca_down';

    $report = Report::validateAndCreate($document);

    expect($report->workloads[1]->failure->code)->toBeNull()
        ->and($report->workloads[0]->health->hint)->toBeNull()
        ->and($report->events[2]->code)->toBeNull()
        ->and($report->ingress->reason)->toBeNull()
        ->and($report->ingress->certificates[0]->failureKind)->toBeNull();
});

test('the runtime contract and cleanup completions decode and re-encode schema-valid', function (string $fixture): void {
    $completion = TaskCompletion::validateAndCreate(opsFixture($fixture));

    expect(ProtocolSchemas::errors('task-completion', (string) json_encode(opsWithoutNulls($completion->toArray()))))->toBe([]);
})->with(['task-completion.build-runtime', 'task-completion.cleanup']);

test('the runtime contract reaches the BuildSummary mirror', function (): void {
    $summary = TaskCompletion::validateAndCreate(opsFixture('task-completion.build-runtime'))->results->build?->summary;

    expect($summary?->envAliases[0]->from)->toBe(EnvAliasSource::PublicUrl)
        ->and($summary?->requiredSecrets[0]->name)->toBe('RAILS_MASTER_KEY')
        ->and($summary?->volumeSuggestions[0]->path)->toBe('/rails/storage')
        ->and($summary?->workspace?->dependsOn)->toBe(['packages/ui', 'packages/config'])
        ->and($summary?->phpDependencyExtensions[1]->for)->toBe('laravel/horizon')
        ->and($summary?->generatedSecrets[1]->shared)->toBe('service')
        ->and($summary?->presets[0]->scope)->toBe('runtime')
        ->and($summary?->processes[0]->stopTimeout)->toBe('60s')
        ->and($summary?->image?->exposedPorts)->toBe([3000]);
});

function opsState(): NodeState
{
    return new NodeState(
        serverId: 'srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK',
        version: 3,
        issuedAt: CarbonImmutable::parse('2026-11-17T12:00:00Z'),
        system: new SystemSpec(
            new SystemContainer(true, '127.0.0.1:5000/mirror/caddy@sha256:'.str_repeat('a', 64)),
            new SystemContainer(false),
            new TailscaleSpec(false),
            watchdog: new WatchdogSpec('control_plane', 'slack', base64_encode(str_repeat('s', 80)), 'fp-1', serverLabel: 'main server'),
        ),
        networks: [new NetworkSpec('net_01J9Z8X7W6V5T4S3R2Q1P0N9N0', 'lr-e_7f3c2a91b0d4e5f6', '10.231.4.0/24')],
        volumes: [new VolumeSpec('vol_01J9Z8X7W6V5T4S3R2Q1P0N9V1', 'lr-v_a')],
        workloads: [new WorkloadSpec(
            id: 'wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7', service: 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2', environment: 'env_01J9Z8X7W6V5T4S3R2Q1P0N9N4',
            deployment: 'dpl_01J9Z8X7W6V5T4S3R2Q1P0N9N5', image: '127.0.0.1:5000/apps/svc@sha256:'.str_repeat('b', 64),
            sealedEnv: base64_encode(str_repeat('e', 80)), envFingerprint: 'x', cpus: 1.0, memoryMb: 256, pids: 256, networks: [['network' => 'net_01J9Z8X7W6V5T4S3R2Q1P0N9N0', 'aliases' => ['web']]], role: 'serving',
            port: 8000,
            health: new HealthSpec('http', '30s', '5s', '3s', 3, '/', '2xx-4xx', 'shop.example.com', '5m'),
            mounts: [['volume' => 'vol_01J9Z8X7W6V5T4S3R2Q1P0N9V1', 'target' => '/data', 'initOwner' => ['uid' => 1000, 'gid' => 1000]]],
        )],
    );
}

test('the operations NodeState fields render as their schema says', function (): void {
    $document = json_decode(ProtocolJson::encode(opsState()), true);

    expect($document['system']['watchdog'])->toBe(['role' => 'control_plane', 'fallback' => [
        'kind' => 'slack', 'secret' => ['sealed' => base64_encode(str_repeat('s', 80))], 'serverLabel' => 'main server',
    ]])
        ->and($document['workloads'][0]['health']['host'])->toBe('shop.example.com')
        ->and($document['workloads'][0]['health']['restartAfter'])->toBe('5m')
        ->and($document['workloads'][0]['mounts'][0]['initOwner'])->toBe(['uid' => 1000, 'gid' => 1000])
        // The fingerprint names the sealed secret without its randomized ciphertext.
        ->and(json_encode(opsState()->system->toFingerprint()))->not->toContain(base64_encode(str_repeat('s', 80)));

    expect(ProtocolSchemas::errors('node-state', ProtocolJson::encode(opsState())))->toBe([]);
});

test('a launchroomd without the operations features gets the document without their fields', function (): void {
    $server = Server::factory()->online()->create(['daemon_features' => []]);

    $state = FeatureGate::downgrade(opsState(), $server);

    expect($state->system->watchdog)->toBeNull()
        ->and($state->workloads[0]->health?->host)->toBeNull()
        ->and($state->workloads[0]->health?->restartAfter)->toBeNull()
        ->and($state->workloads[0]->mounts)->toBe([['volume' => 'vol_01J9Z8X7W6V5T4S3R2Q1P0N9V1', 'target' => '/data']]);

    $all = Server::factory()->online()->create(['daemon_features' => array_map(static fn (DaemonFeature $feature): string => $feature->value, DaemonFeature::cases())]);

    expect(FeatureGate::downgrade(opsState(), $all)->toArray())->toEqual(opsState()->toArray());
});

test('host parts without their required keys are refused, next to the report-level controlPlane', function (array $host): void {
    $document = opsFixture('report.ops');
    $document['host'] = [...$document['host'], ...$host];

    Report::validateAndCreate($document);
})->throws(ValidationException::class)->with([
    'controlPlane without state' => [['controlPlane' => ['configured' => 'https://launchroom.example.com']]],
    'lastRefusal without status' => [['lastRefusal' => ['at' => '2026-11-17T09:14:00Z']]],
    'docker without checkedAt' => [['docker' => ['reachable' => false]]],
]);
