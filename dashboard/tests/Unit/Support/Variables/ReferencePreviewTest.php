<?php

declare(strict_types=1);

use App\Support\Variables\EnvFileParser;
use App\Support\Variables\EnvFileWriter;
use App\Support\Variables\ReferencePreview;

function previewScopes(): ReferencePreview
{
    return new ReferencePreview([
        'shared' => ['REGION' => ['value' => 'eu', 'secret' => false]],
        'postgres' => [
            'POSTGRES_PASSWORD' => ['value' => 's3cret', 'secret' => true],
            'DATABASE_URL' => ['value' => 'postgres://app:${{POSTGRES_PASSWORD}}@postgres:5432/app', 'secret' => false],
        ],
        'web' => [
            'A' => ['value' => '${{B}}', 'secret' => false],
            'B' => ['value' => '${{A}}', 'secret' => false],
            'DB' => ['value' => '${{postgres.DATABASE_URL}}', 'secret' => false],
        ],
    ]);
}

test('references expand environment-relative with every secret masked', function (): void {
    expect(previewScopes()->preview('web', 'X', '${{postgres.DATABASE_URL}}?region=${{shared.REGION}}'))
        ->toBe(['preview' => 'postgres://app:'.ReferencePreview::MASK.'@postgres:5432/app?region=eu', 'errors' => []]);
});

test('broken references and cycles are reported, never thrown', function (): void {
    $preview = previewScopes();

    expect($preview->preview('web', 'X', '${{nope.KEY}}')['errors'])->toBe(["\${{nope.KEY}} refers to a service that doesn't exist in this environment"])
        ->and($preview->preview('web', 'X', '${{postgres.MISSING}}')['errors'])->toBe(["\${{postgres.MISSING}} refers to a variable that doesn't exist"])
        ->and($preview->preview('web', 'A', '${{B}}')['errors'])->toBe(['${{A}} is part of a reference cycle'])
        ->and($preview->preview('web', 'X', '${{ secret(32) }}')['errors'])->toBe(['${{ secret(32) }} is not a valid reference']);
});

test('the env writer round-trips through the parser', function (string $value): void {
    $parsed = EnvFileParser::parse(EnvFileWriter::line('KEY', $value)."\n");

    expect($parsed->errors)->toBe([])
        ->and($parsed->entries[0]->value)->toBe($value);
})->with([
    'plain' => ['simple'],
    'spaces' => ['hello world'],
    'hash' => ['a #not-a-comment'],
    'quotes' => ['say "hi" and \'bye\''],
    'backslash' => ['C:\\path\\to'],
    'multiline' => ["line one\nline two\r\n\ttab"],
    'reference' => ['${{postgres.DATABASE_URL}}'],
    'empty' => [''],
    'url' => ['https://user:pa$$@host:5432/db?x=1&y=2'],
]);
