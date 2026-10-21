<?php

declare(strict_types=1);

use App\Support\ConfigAsCode\JsonDocument;
use App\Support\ConfigAsCode\JsonSyntaxError;

function jdError(string $json): JsonSyntaxError
{
    try {
        JsonDocument::parse($json);
    } catch (JsonSyntaxError $e) {
        return $e;
    }

    throw new RuntimeException('expected a syntax error');
}

test('it parses JSON like json_decode and remembers where values and keys start', function (): void {
    $json = "{\n  \"version\": 1,\n  \"services\": {\n    \"web\": { \"kind\": \"app\", \"deploy\": { \"port\": 3000 } }\n  },\n  \"list\": [true, null, -1.5e2, \"a\\u00e9\\ud83d\\ude80\"]\n}\n";
    $document = JsonDocument::parse($json);

    expect($document->toArray())->toBe(json_decode($json, true))
        ->and($document->position('/services/web/deploy/port'))->toBe(['line' => 4, 'column' => 49])
        ->and($document->keyPosition('/services/web'))->toBe(['line' => 4, 'column' => 5])
        ->and($document->position('/list/3'))->toBe(['line' => 6, 'column' => 32])
        ->and($document->nearest('/services/web/build/missing'))->toBe(['line' => 4, 'column' => 12]);
});

test('objects stay objects for the schema validator, also when empty', function (): void {
    $document = JsonDocument::parse('{"a": {}, "b": []}');

    expect($document->value)->toBeInstanceOf(stdClass::class)
        ->and($document->value->a)->toBeInstanceOf(stdClass::class)
        ->and($document->value->b)->toBe([]);
});

test('columns count characters, not bytes', function (): void {
    $document = JsonDocument::parse('{"name": "Café", "x": 1}');

    expect($document->position('/x'))->toBe(['line' => 1, 'column' => 23]);
});

test('common mistakes get a friendly message at the right place', function (string $json, string $message, int $line, int $column, ?string $hint): void {
    $error = jdError($json);

    expect($error->getMessage())->toContain($message)
        ->and([$error->atLine, $error->atColumn])->toBe([$line, $column]);

    if ($hint !== null) {
        expect($error->hint)->toContain($hint);
    }
})->with([
    'trailing comma in an object' => ["{\n  \"a\": 1,\n}", 'Unexpected } after a comma', 2, 9, 'trailing comma'],
    'trailing comma in a list' => ['{"a": [1, 2,]}', 'Unexpected ] after a comma', 1, 12, 'trailing comma'],
    'line comment' => ["{\n  // hi\n  \"a\": 1\n}", 'Comments aren\'t allowed', 2, 3, null],
    'block comment' => ['{"a": 1 /* x */}', 'Comments aren\'t allowed', 1, 9, null],
    'single quotes' => ["{'a': 1}", 'Keys need double quotes', 1, 2, null],
    'unquoted key' => ['{port: 1}', 'Expected a key in double quotes', 1, 2, '"port"'],
    'duplicate key' => ["{\n  \"port\": 1,\n  \"port\": 2\n}", '"port" is set twice in this object (first on line 2)', 3, 3, null],
    'missing comma' => ["{\"a\": 1\n \"b\": 2}", 'Expected , or }', 2, 2, 'Add a comma'],
    'Python booleans' => ['{"a": True}', 'True is not JSON', 1, 7, 'lowercase'],
    'unterminated string' => ['{"a": "abc', 'never closed', 1, 7, null],
    'line break in a string' => ["{\"a\": \"x\ny\"}", 'A line break inside a string', 1, 9, null],
    'leading zero' => ['{"a": 012}', 'not valid JSON', 1, 8, null],
    'empty file' => ['   ', 'The file is empty', 1, 4, null],
    'text after the object' => ['{} {}', 'after the end of the document', 1, 4, null],
    'bad escape' => ['{"a": "\q"}', 'Unknown escape', 1, 8, null],
]);

test('it refuses documents that are too large or too deep', function (): void {
    expect(jdError('{"a": "'.str_repeat('x', JsonDocument::MAX_BYTES).'"}')->getMessage())->toContain('larger than 256 KB')
        ->and(jdError(str_repeat('[', 20).str_repeat(']', 20))->getMessage())->toContain('nested too deeply');
});

test('a byte order mark is skipped', function (): void {
    expect(JsonDocument::parse("\u{FEFF}{\"a\": 1}")->toArray())->toBe(['a' => 1]);
});
