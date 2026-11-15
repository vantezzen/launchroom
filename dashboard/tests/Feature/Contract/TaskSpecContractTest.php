<?php

declare(strict_types=1);

use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\Task;
use App\Data\Protocol\V1\TaskLease;
use App\Data\Protocol\V1\Tasks\TaskSpec;
use App\Enums\DaemonTaskType;
use Carbon\CarbonImmutable;
use Tests\Support\ProtocolSchemas;

/*
| The typed task specs (App\Data\Protocol\V1\Tasks) mirror Go's
| state.TaskSpec types: every Go lease fixture decodes into them and encodes
| back to exactly the same document.
*/

/**
 * @return array<string, array{string}>
 */
function taskLeaseFixtures(): array
{
    $fixtures = [];

    foreach (glob(ProtocolSchemas::root().'/fixtures/v1/task-lease.*.json') ?: [] as $path) {
        $fixtures[basename($path, '.json')] = [$path];
    }

    return $fixtures;
}

test('every lease fixture round-trips through the typed task specs', function (string $path): void {
    $fixture = (string) file_get_contents($path);
    $lease = json_decode($fixture, false);
    $tasks = array_map(static fn (object $task): Task => new Task(
        $task->id,
        DaemonTaskType::from($task->type),
        CarbonImmutable::parse($task->leaseExpiresAt),
        $task->attempt,
        CarbonImmutable::parse($task->deadline),
        TaskSpec::fromStored(DaemonTaskType::from($task->type), $task->spec),
    ), $lease->tasks);

    $encoded = ProtocolJson::encode(new TaskLease($tasks));

    expect(ProtocolSchemas::errors('task-lease', $encoded))->toBe([])
        ->and(json_decode($encoded, true))->toEqual(json_decode($fixture, true));
})->with(taskLeaseFixtures());

test('an empty railpackEnv map encodes as a JSON object', function (): void {
    $lease = json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/v1/task-lease.build.json'), false);
    $build = collect($lease->tasks)->firstWhere('type', 'build');
    $build->spec->builder->railpackEnv = new stdClass;

    $encoded = json_encode(TaskSpec::fromStored(DaemonTaskType::Build, $build->spec)->toProtocol(), JSON_THROW_ON_ERROR);

    expect($encoded)->toContain('"railpackEnv":{}');
});

test('the lease fixtures cover every task type', function (): void {
    $types = [];

    foreach (taskLeaseFixtures() as [$path]) {
        foreach (json_decode((string) file_get_contents($path), false)->tasks as $task) {
            $types[$task->type] = true;
        }
    }

    $missing = array_values(array_diff(array_map(static fn (DaemonTaskType $type): string => $type->value, DaemonTaskType::cases()), array_keys($types)));

    // Specs without a Go fixture yet: their shape is checked against the schema by the flow tests.
    expect(array_values(array_diff($missing, ['logs', 'verify_restore'])))->toBe([]);
});

test('a spec that does not decode is refused with the type it claims', function (): void {
    TaskSpec::fromStored(DaemonTaskType::Build, (object) ['service' => 'svc_x']);
})->throws(InvalidArgumentException::class, 'The build spec doesn’t decode');
