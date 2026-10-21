<?php

declare(strict_types=1);

use App\Data\Protocol\V1\DataExportResult;
use App\Data\Protocol\V1\DataImportResult;
use App\Data\Protocol\V1\TaskCompletion;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;
use Tests\Support\ProtocolSchemas;

/*
| docs/10 §2.4: the Go fixtures of the data import/export tasks decode into
| the PHP Data objects, and every task type and feature is one the dashboard knows.
*/

function transferFixture(string $name): array
{
    return json_decode((string) file_get_contents(ProtocolSchemas::root()."/fixtures/v1/{$name}.json"), true, 32, JSON_THROW_ON_ERROR);
}

test('import and export completions decode into the Data mirrors', function (): void {
    $import = TaskCompletion::validateAndCreate(transferFixture('task-completion.data-import'));
    $export = TaskCompletion::validateAndCreate(transferFixture('task-completion.data-export'));

    expect($import->dataImport)->toBeInstanceOf(DataImportResult::class)
        ->and($import->dataImport instanceof DataImportResult ? $import->dataImport->tables : null)->toBe(14)
        ->and($export->dataExport)->toBeInstanceOf(DataExportResult::class)
        ->and($export->dataExport instanceof DataExportResult ? $export->dataExport->compressed : null)->toBeTrue();
});

test('a completion with an unknown format or too many warnings is refused', function (): void {
    $document = transferFixture('task-completion.data-import');
    $document['dataImport']['format'] = 'oracle_dump';
    expect(fn (): TaskCompletion => TaskCompletion::validateAndCreate($document))->toThrow(ValidationException::class);

    $document = transferFixture('task-completion.data-import');
    $document['dataImport']['warnings'] = array_fill(0, 9, 'x');
    expect(fn (): TaskCompletion => TaskCompletion::validateAndCreate($document))->toThrow(ValidationException::class);
});

test('the lease fixture uses task types and the feature the dashboard knows', function (): void {
    foreach (transferFixture('task-lease.data-transfer')['tasks'] as $task) {
        expect(DaemonTaskType::tryFrom($task['type']))->not->toBeNull();
    }

    expect(DaemonFeature::tryFrom('dataTransfer'))->toBe(DaemonFeature::DataTransfer)
        ->and(ProtocolSchemas::errors('task-lease', (string) json_encode(transferFixture('task-lease.data-transfer'))))->toBe([]);
});

test('stored completion results re-encode schema-valid', function (string $fixture): void {
    $completion = TaskCompletion::validateAndCreate(transferFixture($fixture));
    $document = array_filter($completion->toArray(), static fn (mixed $value): bool => $value !== null && ! $value instanceof Optional);

    expect(ProtocolSchemas::errors('task-completion', (string) json_encode($document)))->toBe([]);
})->with(['task-completion.data-import', 'task-completion.data-export']);
