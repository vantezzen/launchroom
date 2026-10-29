<?php

declare(strict_types=1);

use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\LogBatch;
use App\Data\Protocol\V1\Task;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Protocol\V1\TaskLease;
use App\Enums\DaemonTaskType;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;
use Tests\Support\ProtocolSchemas;

/*
| Contract tests for build/plan/run_once (docs/04 §12–13, protocol CHANGELOG):
| the Go completion fixtures decode into the PHP Data mirrors, and lease
| documents for every task type round-trip schema-valid.
*/

function taskFixture(string $name): string
{
    return (string) file_get_contents(ProtocolSchemas::root()."/fixtures/v1/{$name}.json");
}

test('build, plan, failed-build and run_once completions decode into the Data mirrors', function (): void {
    $build = TaskCompletion::validateAndCreate(json_decode(taskFixture('task-completion.build'), true));
    $plan = TaskCompletion::validateAndCreate(json_decode(taskFixture('task-completion.plan'), true));
    $failed = TaskCompletion::validateAndCreate(json_decode(taskFixture('task-completion.build-failed'), true));
    $runOnce = TaskCompletion::validateAndCreate(json_decode(taskFixture('task-completion.run-once'), true));

    expect($build->build instanceof Optional ? null : $build->build?->summary?->presets[0]->decisions[0]->id)->toBe('port-contract')
        ->and($build->build instanceof Optional ? null : $build->build?->attestations)->toBe(['sbom', 'provenance'])
        ->and($build->build instanceof Optional ? null : $build->build?->secretsHash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($plan->plan instanceof Optional ? null : $plan->plan?->summary->releaseCommand)->toBe(['php', 'artisan', 'migrate', '--force'])
        ->and($plan->plan instanceof Optional ? null : $plan->plan?->summary->processes[0]->args)->toBe(['php', 'artisan', 'horizon'])
        ->and($plan->plan instanceof Optional ? null : $plan->plan?->summary->runtimeEnv)->toBe(['RAILPACK_SKIP_MIGRATIONS' => 'true'])
        ->and($plan->plan instanceof Optional ? null : $plan->plan?->summary->phpExtensions[0]->name)->toBe('pdo_pgsql')
        ->and($failed->failure instanceof Optional ? null : $failed->failure?->class)->toBe('build_error')
        ->and($failed->failure instanceof Optional ? null : $failed->failure?->hints[0]->code)->toBe('npm-ci-lockfile')
        ->and($failed->failure instanceof Optional ? null : $failed->failure?->summary?->port)->toBe(3000)
        ->and($runOnce->runOnce instanceof Optional ? null : $runOnce->runOnce?->exitCode)->toBe(1);
});

test('stored completion results re-encode schema-valid', function (string $fixture): void {
    $completion = TaskCompletion::validateAndCreate(json_decode(taskFixture($fixture), true));
    $document = array_filter($completion->toArray(), static fn (mixed $value): bool => $value !== null);

    expect(ProtocolSchemas::errors('task-completion', (string) json_encode(stripNulls($document))))->toBe([]);
})->with(['task-completion.build', 'task-completion.build-next', 'task-completion.plan', 'task-completion.build-failed', 'task-completion.run-once', 'task-completion.self-update']);

test('the self_update completion decodes into the SelfUpdateResult mirror', function (): void {
    $completion = TaskCompletion::validateAndCreate(json_decode(taskFixture('task-completion.self-update'), true));
    $result = $completion->selfUpdate instanceof Optional ? null : $completion->selfUpdate;

    expect($result?->scope)->toBe('daemon')
        ->and($result?->fromVersion)->toBe('0.1.0')
        ->and($result?->toVersion)->toBe('0.2.0')
        ->and($result?->rolledBack)->toBeTrue();
});

test('the Next.js adapter report decodes into the BuildSummary mirror', function (): void {
    $completion = TaskCompletion::validateAndCreate(json_decode(taskFixture('task-completion.build-next'), true));
    $next = $completion->build instanceof Optional ? null : $completion->build?->summary?->next;

    expect($next?->nextVersion)->toBe('16.4.2')
        ->and($next?->adapterVersion)->toBe('0.1.0')
        ->and($next?->serverEntry)->toBe('.next/standalone/server.js')
        ->and($next?->middleware)->toBeTrue()
        ->and($next?->middlewareRuntime)->toBe('nodejs')
        ->and($next?->routes->isr)->toBe(1)
        ->and($next?->routes->appPages)->toBe(2)
        ->and($next?->isrRoutes)->toBe(['/isr']);
});

test('an out-of-range Next.js report is rejected', function (): void {
    $document = json_decode(taskFixture('task-completion.build-next'), true);
    $document['build']['summary']['next']['routes']['isr'] = -1;

    TaskCompletion::validateAndCreate($document);
})->throws(ValidationException::class);

test('plan and run_once lease fixtures round-trip through the PHP task document', function (string $fixture): void {
    $lease = json_decode(taskFixture($fixture), false);
    $tasks = array_map(static fn (object $task): Task => new Task(
        $task->id,
        DaemonTaskType::from($task->type),
        CarbonImmutable::parse($task->leaseExpiresAt),
        $task->attempt,
        CarbonImmutable::parse($task->deadline),
        $task->spec,
    ), $lease->tasks);

    $encoded = ProtocolJson::encode(new TaskLease($tasks));

    expect(ProtocolSchemas::errors('task-lease', $encoded))->toBe([])
        ->and(json_decode($encoded, true))->toEqual(json_decode(taskFixture($fixture), true));
})->with(['task-lease.plan', 'task-lease.plan-php', 'task-lease.run-once', 'task-lease.build']);

test('step-grouped build log lines are accepted', function (): void {
    $batch = LogBatch::validateAndCreate(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LogBatch', 'chunks' => [[
        'source' => ['kind' => 'task', 'id' => 'tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P0'],
        'seq' => 1_000_000_001,
        'lines' => [['time' => '2026-10-17T12:00:00.123456789Z', 'stream' => 'stdout', 'text' => 'npm ci', 'step' => 'install']],
    ]]]);

    expect($batch->chunks[0]->lines[0]->step)->toBe('install')
        ->and(ProtocolSchemas::errors('log-batch', (string) json_encode($batch->toArray())))->toBe([]);
});

/**
 * @param  array<mixed>  $value
 * @return array<mixed>
 */
function stripNulls(array $value): array
{
    $out = [];

    foreach ($value as $key => $item) {
        if ($item === null) {
            continue;
        }

        $out[$key] = is_array($item) ? stripNulls($item) : $item;
    }

    return $out;
}
