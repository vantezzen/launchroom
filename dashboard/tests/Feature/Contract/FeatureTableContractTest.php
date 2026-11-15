<?php

declare(strict_types=1);

use App\Data\Protocol\V1\Tasks\TaskSpec;
use App\Enums\Backups\BackupSubject;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;
use App\Enums\DatabaseEngine;
use App\Enums\NodeStateField;
use Tests\Support\ProtocolSchemas;

/*
| The feature tables of the dashboard (NodeStateField for NodeState fields,
| TaskSpec::requiredFeatures() for task specs) agree with the one launchroomd
| generates from its types (protocol/schema/v1/vocabulary.json).
*/

/**
 * @return array<string, mixed>
 */
function vocabulary(): array
{
    return json_decode((string) file_get_contents(ProtocolSchemas::root().'/schema/v1/vocabulary.json'), true);
}

test('NodeStateField covers every optional NodeState field with its feature', function (): void {
    $generated = array_filter(vocabulary()['fieldFeatures'], static fn (string $path): bool => str_starts_with($path, 'NodeState.'), ARRAY_FILTER_USE_KEY);
    $ours = [];

    foreach (NodeStateField::cases() as $field) {
        // Not fields: owner-less volumes (a behaviour) and DNS providers (a value).
        if ($field === NodeStateField::PreparedVolume || $field === NodeStateField::IngressDnsProvider) {
            continue;
        }

        $ours['NodeState.'.$field->value] = $field->feature()->value;
    }

    ksort($generated);
    ksort($ours);

    expect($ours)->toBe($generated)
        ->and(array_unique(array_values(vocabulary()['valueFeatures']['DNSProvider'])))->toBe([NodeStateField::IngressDnsProvider->feature()->value]);
});

test('engine and backup-kind values need the features the dashboard gates them on', function (): void {
    $values = vocabulary()['valueFeatures'];
    $engines = [];

    foreach (DatabaseEngine::cases() as $engine) {
        if ($engine->daemonFeature() instanceof DaemonFeature) {
            $engines[$engine->value] = $engine->daemonFeature()->value;
        }
    }

    ksort($engines);
    ksort($values['DatabaseEngine']);

    // Base backups are offered with point-in-time recovery, which the WAL archive field gates.
    expect($engines)->toBe($values['DatabaseEngine'])
        ->and($values['BackupKind'])->toBe([BackupSubject::BaseBackup->value => NodeStateField::WorkloadWalArchive->feature()->value]);
});

test('a task spec requires the features of the fields and values it carries', function (string $path): void {
    $vocabulary = vocabulary();

    foreach (json_decode((string) file_get_contents($path), true)['tasks'] as $task) {
        $type = DaemonTaskType::from($task['type']);
        $expected = [];

        foreach ($vocabulary['fieldFeatures'] as $field => $feature) {
            $prefix = "Task({$type->value}).spec.";

            if (str_starts_with($field, $prefix) && data_get($task['spec'], substr($field, strlen($prefix))) !== null) {
                $expected[] = $feature;
            }
        }

        foreach (['repo.provider', 'source.repo.provider'] as $provider) {
            $expected[] = $vocabulary['valueFeatures']['GitProvider'][data_get($task['spec'], $provider)] ?? null;
        }

        if (in_array($type, [DaemonTaskType::Inventory, DaemonTaskType::InspectContainer], true)) {
            $expected[] = $vocabulary['taskFeatures'][$type->value];
        }

        $required = array_map(static fn (DaemonFeature $feature): string => $feature->value, TaskSpec::fromStored($type, $task['spec'])->requiredFeatures());

        expect($required)->toEqualCanonicalizing(array_values(array_unique(array_filter($expected))));
    }
})->with(function (): array {
    $fixtures = [];

    foreach (glob(ProtocolSchemas::root().'/fixtures/v1/task-lease.*.json') ?: [] as $path) {
        $fixtures[basename($path, '.json')] = [$path];
    }

    return $fixtures;
});
