<?php

declare(strict_types=1);

use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\VolumeConsistency;
use App\Enums\Backups\WalArchiveState;
use App\Enums\Builds\BuilderReason;
use App\Enums\Builds\BuildHintCode;
use App\Enums\Builds\ChosenBuilder;
use App\Enums\CertStatus;
use App\Enums\ClientCertificateMode;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;
use App\Enums\DatabaseEngine;
use App\Enums\DnsProvider;
use App\Enums\Git\GitProviderKind;
use App\Enums\HealthcheckType;
use App\Enums\LogSourceKind;
use App\Enums\LogStream;
use App\Enums\Mesh\MeshPath;
use App\Enums\PortHint;
use App\Enums\ProbeState;
use App\Enums\RemoteAccess\RemoteAccessEndReason;
use App\Enums\RemoteAccess\RemoteAccessKind;
use App\Enums\Security\WafCategory;
use App\Enums\Security\WafMode;
use App\Enums\Servers\CapabilityReason;
use App\Enums\Servers\CapabilityState;
use App\Enums\Servers\DockerEngine;
use App\Enums\Servers\DockerMode;
use App\Enums\Servers\HostOs;
use App\Enums\Servers\RuntimeCapability;
use App\Enums\Servers\Virtualization;
use App\Enums\Sessions\InteractiveSessionKind;
use App\Enums\Sessions\SessionEndReason;
use App\Enums\Sessions\StartFailure;
use App\Enums\TaskFailureClass;
use App\Enums\Transfers\ImportSourceKind;
use App\Enums\Updates\UpdateScope;
use App\Enums\WorkloadCapability;
use App\Enums\WorkloadEventKind;
use App\Enums\WorkloadEventReason;
use App\Enums\WorkloadIssueKind;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Support\Builds\PhpExtensions;
use App\Support\ServerPlatform;
use Tests\Support\ProtocolSchemas;

/*
| Every PHP enum that mirrors a protocol enum has exactly the values of the
| schema launchroomd generates (protocol/schema/v1), so a new task type,
| phase or code fails here instead of as a ValueError on a live report.
| A dashboard enum may differ only where it says why.
*/

/**
 * The string enum at $pointer ("file.json#/json/pointer") of the generated schemas.
 *
 * @return list<string>
 */
function schemaEnum(string $pointer): array
{
    [$file, $path] = explode('#', $pointer, 2);
    $node = json_decode((string) file_get_contents(ProtocolSchemas::root().'/schema/v1/'.$file), true, 512, JSON_THROW_ON_ERROR);

    foreach (array_filter(explode('/', $path), static fn (string $part): bool => $part !== '') as $part) {
        expect($node)->toBeArray()->toHaveKey($part);
        $node = $node[$part];
    }

    expect($node)->toBeArray()->toHaveKey('enum');

    return $node['enum'];
}

/**
 * @param  class-string<BackedEnum>  $enum
 * @return list<string>
 */
function enumValues(string $enum): array
{
    return array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases());
}

test('mirror enums have the schema\'s values', function (string $enum, string $pointer): void {
    $ours = enumValues($enum);
    $theirs = schemaEnum($pointer);
    sort($ours);
    sort($theirs);

    expect($ours)->toBe($theirs);
})->with([
    'DaemonTaskType' => [DaemonTaskType::class, 'lease-request.json#/properties/types/items'],
    'WorkloadPhase' => [WorkloadPhase::class, 'report.json#/$defs/WorkloadStatus/properties/phase'],
    'WorkloadRole' => [WorkloadRole::class, 'node-state.json#/$defs/WorkloadSpec/properties/role'],
    'LogSourceKind' => [LogSourceKind::class, 'log-batch.json#/$defs/LogSource/properties/kind'],
    'LogStream' => [LogStream::class, 'log-batch.json#/$defs/LogLine/properties/stream'],
    'RemoteAccessKind' => [RemoteAccessKind::class, 'task-lease.json#/$defs/AccessSpec/properties/kind'],
    'RemoteAccessEndReason' => [RemoteAccessEndReason::class, 'task-completion.json#/$defs/AccessResult/properties/endReason'],
    'InteractiveSessionKind' => [InteractiveSessionKind::class, 'task-lease.json#/$defs/SessionSpec/properties/kind'],
    'SessionEndReason' => [SessionEndReason::class, 'task-completion.json#/$defs/SessionResult/properties/endReason'],
    'StartFailure (session)' => [StartFailure::class, 'task-completion.json#/$defs/SessionResult/properties/failure'],
    'StartFailure (access)' => [StartFailure::class, 'task-completion.json#/$defs/AccessResult/properties/failure'],
    'RestoreMode' => [RestoreMode::class, 'task-lease.json#/$defs/RestoreSpec/properties/mode'],
    'BackupSubject' => [BackupSubject::class, 'task-lease.json#/$defs/BackupSpec/properties/kind'],
    'VolumeConsistency' => [VolumeConsistency::class, 'task-lease.json#/$defs/BackupTarget/properties/consistency'],
    'ImportSourceKind' => [ImportSourceKind::class, 'task-lease.json#/$defs/DbImportSource/properties/kind'],
    'WalArchiveState' => [WalArchiveState::class, 'report.json#/$defs/WalArchiveStatus/properties/state'],
    'UpdateScope' => [UpdateScope::class, 'task-completion.json#/$defs/SelfUpdateResult/properties/scope'],
    'GitProviderKind' => [GitProviderKind::class, 'task-lease.json#/$defs/RepoSpec/properties/provider'],
    'TaskFailureClass' => [TaskFailureClass::class, 'task-completion.json#/$defs/TaskFailure/properties/class'],
    'BuilderReason' => [BuilderReason::class, 'task-completion.json#/$defs/BuildSummary/properties/builderReason'],
    'WafCategory' => [WafCategory::class, 'report.json#/$defs/WafCategoryCount/properties/category'],
    'RuntimeCapability' => [RuntimeCapability::class, 'report.json#/$defs/HostCapability/properties/name'],
    'CapabilityState' => [CapabilityState::class, 'report.json#/$defs/HostCapability/properties/state'],
    'CapabilityReason' => [CapabilityReason::class, 'report.json#/$defs/HostCapability/properties/reason'],
    'DockerMode' => [DockerMode::class, 'report.json#/$defs/HostRuntime/properties/docker'],
    'DockerEngine' => [DockerEngine::class, 'report.json#/$defs/HostRuntime/properties/engine'],
    'HostOs' => [HostOs::class, 'report.json#/$defs/HostRuntime/properties/hostOs'],
    'MeshPath' => [MeshPath::class, 'report.json#/$defs/MeshPeer/properties/connection'],
    'DnsProvider' => [DnsProvider::class, 'node-state.json#/$defs/DNSChallengeSpec/properties/provider'],
    'ProbeState' => [ProbeState::class, 'report.json#/$defs/ProbeStatus/properties/state'],
    'CertStatus' => [CertStatus::class, 'report.json#/$defs/CertificateStatus/properties/state'],
    'PortHint' => [PortHint::class, 'report.json#/$defs/WorkloadStatus/properties/portHint'],
    'WorkloadIssueKind' => [WorkloadIssueKind::class, 'report.json#/$defs/WorkloadIssue/properties/kind'],
    'WorkloadEventKind' => [WorkloadEventKind::class, 'report.json#/$defs/WorkloadEvent/properties/kind'],
    'WorkloadEventReason' => [WorkloadEventReason::class, 'report.json#/$defs/WorkloadEvent/properties/reason'],
    'Virtualization (report)' => [Virtualization::class, 'report.json#/$defs/HardwareFacts/properties/virtualization'],
    'Virtualization (enroll)' => [Virtualization::class, 'enroll-request.json#/$defs/HardwareFacts/properties/virtualization'],
    'WorkloadCapability' => [WorkloadCapability::class, 'capability-allow-list.json#'],
]);

test('dashboard enums that differ from the schema say why', function (string $enum, string $pointer, array $onlyOurs, array $onlyTheirs): void {
    $ours = enumValues($enum);
    $theirs = schemaEnum($pointer);

    expect(array_values(array_diff($ours, $theirs)))->toBe($onlyOurs)
        ->and(array_values(array_diff($theirs, $ours)))->toBe($onlyTheirs);
})->with([
    // `none`, `off`: the dashboard's word for "no such field on the wire".
    'HealthcheckType' => [HealthcheckType::class, 'node-state.json#/$defs/HealthSpec/properties/type', ['none'], []],
    'WafMode' => [WafMode::class, 'node-state.json#/$defs/RouteWaf/properties/mode', ['off'], []],
    'ClientCertificateMode' => [ClientCertificateMode::class, 'node-state.json#/$defs/RouteClientAuth/properties/mode', ['off'], []],
    // A summary names the builder the plan chose; `auto` is only an input (BuilderSpec.kind).
    'ChosenBuilder' => [ChosenBuilder::class, 'task-completion.json#/$defs/BuildSummary/properties/builder', [], ['auto']],
    // launchroomd can back up and reach MariaDB; the dashboard doesn't offer it as a service.
    'DatabaseEngine' => [DatabaseEngine::class, 'task-lease.json#/$defs/AccessSpec/properties/engine', [], ['mariadb']],
]);

test('the dashboard\'s platforms are the protocol\'s', function (): void {
    expect(ServerPlatform::ALL)->toBe(schemaEnum('report.json#/$defs/HostRuntime/properties/buildPlatforms/items'))
        ->and(ServerPlatform::ALL)->toBe(schemaEnum('task-completion.json#/$defs/InventoryResult/properties/platform'));
});

test('the PHP extensions and their engines are the protocol\'s, and every driver asks for a known one', function (): void {
    $names = PhpExtensions::NAMES;
    $engines = PhpExtensions::ENGINES;
    $theirNames = schemaEnum('task-lease.json#/$defs/PhpExtension/properties/name');
    $theirEngines = schemaEnum('task-lease.json#/$defs/PhpExtension/properties/for');
    sort($names);
    sort($engines);
    sort($theirNames);
    sort($theirEngines);

    expect($names)->toBe($theirNames)->and($engines)->toBe($theirEngines);

    $drivers = resolve(DatabaseEngines::class);

    foreach (DatabaseEngine::cases() as $engine) {
        $extension = $drivers->for($engine)->phpExtension();

        expect($extension === null || in_array($extension, PhpExtensions::NAMES, true))->toBeTrue($engine->value);
    }
});

test('DaemonFeature is every feature of the protocol', function (): void {
    $vocabulary = json_decode((string) file_get_contents(ProtocolSchemas::root().'/schema/v1/vocabulary.json'), true, 512, JSON_THROW_ON_ERROR);
    $ours = enumValues(DaemonFeature::class);
    sort($ours);

    expect($ours)->toBe($vocabulary['features']);
});

test('BuildHintCode is every build hint code launchroomd reports', function (): void {
    $vocabulary = json_decode((string) file_get_contents(ProtocolSchemas::root().'/schema/v1/vocabulary.json'), true, 512, JSON_THROW_ON_ERROR);
    $ours = enumValues(BuildHintCode::class);
    sort($ours);

    expect($ours)->toBe($vocabulary['hintCodes']);
});

test('the capabilities config-as-code offers are launchroomd\'s allow-list', function (): void {
    $config = json_decode((string) file_get_contents(resource_path('schema/launchroom.v1.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($config['$defs']['deploy']['properties']['capabilities']['items']['enum'])->toBe(enumValues(WorkloadCapability::class));
});
