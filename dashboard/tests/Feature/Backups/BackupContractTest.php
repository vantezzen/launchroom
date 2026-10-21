<?php

declare(strict_types=1);

use App\Data\Protocol\V1\BackupResult;
use App\Data\Protocol\V1\BackupSnapshotsResult;
use App\Data\Protocol\V1\RestoreResult;
use App\Data\Protocol\V1\TaskCompletion;
use App\Enums\DaemonTaskType;
use Tests\Support\ProtocolSchemas;

/*
| docs/10 §2.4: the Go fixtures for backup tasks decode into the PHP Data
| objects, and every backup task type is one the dashboard knows.
*/

test('backup completion fixtures decode into TaskCompletion', function (string $fixture, string $field, string $class): void {
    $json = (string) file_get_contents(ProtocolSchemas::root()."/fixtures/v1/{$fixture}.json");
    $completion = TaskCompletion::validateAndCreate(json_decode($json, true, 32, JSON_THROW_ON_ERROR));

    expect($completion->{$field})->toBeInstanceOf($class);
})->with([
    'backup' => ['task-completion.backup', 'backup', BackupResult::class],
    'restore' => ['task-completion.restore', 'restore', RestoreResult::class],
    'snapshots' => ['task-completion.snapshots', 'snapshots', BackupSnapshotsResult::class],
]);

test('the backup lease fixture uses only task types the dashboard knows', function (): void {
    $lease = json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/v1/task-lease.backup.json'), true, 32, JSON_THROW_ON_ERROR);

    foreach ($lease['tasks'] as $task) {
        expect(DaemonTaskType::tryFrom($task['type']))->not->toBeNull();
    }

    $schema = json_decode((string) file_get_contents(ProtocolSchemas::root().'/schema/v1/task.json'), true, 64, JSON_THROW_ON_ERROR);
    expect(json_encode($schema, JSON_THROW_ON_ERROR))->toContain('backup_snapshots');
});
