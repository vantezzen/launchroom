<?php

declare(strict_types=1);

use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Drivers\DatabaseEngines\DumpImport;
use App\Enums\DatabaseEngine;
use App\Services\Transfers\FileSniffer;
use App\Services\Transfers\SniffResult;
use Tests\Support\ProtocolSchemas;

/*
| launchroomd decides what a dump is (daemon/internal/backup/sniff.go); the
| dashboard's FileSniffer gives early feedback with the same rules. Both
| read protocol/fixtures/sniff/databases.json.
*/

function sniffVectors(): array
{
    return json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/sniff/databases.json'), true, flags: JSON_THROW_ON_ERROR);
}

function sniffSample(array $sample): SniffResult
{
    $bytes = isset($sample['base64']) ? (string) base64_decode($sample['base64'], true) : $sample['text'];

    return new FileSniffer()->database(($sample['gzip'] ?? false) ? (string) gzencode($bytes) : $bytes);
}

test('FileSniffer reads the shared dump samples like launchroomd', function (): void {
    foreach (sniffVectors()['samples'] as $sample) {
        $result = sniffSample($sample);
        $expect = $sample['expect'];

        if ($expect['generic'] ?? false) {
            expect($result->generic)->toBeTrue($sample['name'])
                ->and($result->format)->toBeNull($sample['name']);

            continue;
        }

        expect($result->generic)->toBeFalse($sample['name']);

        if ($expect['refused'] ?? false) {
            expect($result->format)->toBeNull($sample['name']);

            continue;
        }

        expect([$result->format, $result->family, $result->serverVersion, $result->toolVersion, $result->compressed])
            ->toBe([$expect['format'], $expect['family'], $expect['serverVersion'], $expect['toolVersion'] ?? null, $expect['compressed']], $sample['name']);
    }
});

test('FileSniffer refuses the same imports as launchroomd', function (): void {
    $samples = collect(sniffVectors()['samples'])->keyBy('name');

    foreach (sniffVectors()['compatibility'] as $case) {
        $import = resolve(DatabaseEngines::class)->for(DatabaseEngine::from($case['engine']))->importFormat();
        assert($import instanceof DumpImport);
        $refusal = FileSniffer::refusal(sniffSample($samples[$case['sample']]), $import, $case['targetVersion'], 'db');

        expect($refusal === null)->toBe($case['importable'], "{$case['sample']} into {$case['engine']} {$case['targetVersion']}");
    }
});

test('SQL without a dump tool\'s header imports as the target\'s SQL, never into MongoDB', function (): void {
    $generic = new FileSniffer()->database("-- schema\nCREATE TABLE t (id int);\n");
    $import = static fn (DatabaseEngine $engine): DumpImport => resolve(DatabaseEngines::class)->for($engine)->importFormat() ?? throw new LogicException;

    expect(FileSniffer::refusal($generic, $import(DatabaseEngine::Postgres), '17', 'db'))->toBeNull()
        ->and(FileSniffer::refusal($generic, $import(DatabaseEngine::MySql), '8.4', 'db'))->toBeNull()
        ->and(FileSniffer::refusal($generic, $import(DatabaseEngine::MongoDb), '8.0', 'db'))->toContain('SQL file')
        ->and($generic->forTarget($import(DatabaseEngine::Postgres))->format)->toBe('postgres_sql')
        ->and($generic->detail())->toBe('SQL file');
});
