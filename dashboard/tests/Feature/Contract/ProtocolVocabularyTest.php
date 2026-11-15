<?php

declare(strict_types=1);

use App\Support\Protocol\Grammar;
use App\Support\Protocol\Limits;
use Illuminate\Support\Str;

function protocolVocabulary(): array
{
    $path = rtrim((string) config('launchroom.protocol.schema_path'), '/').'/vocabulary.json';

    return json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
}

it('keeps the generated grammar and limits in step with vocabulary.json', function (): void {
    $this->artisan('launchroom:protocol-vocabulary', ['--check' => true])->assertSuccessful();
});

it('anchors grammars like RE2 does', function (): void {
    expect(preg_match(Grammar::CONTAINER_PATH, '/data'))->toBe(1)
        ->and(preg_match(Grammar::CONTAINER_PATH, "/data\n"))->toBe(0)
        ->and(preg_match(Grammar::CONTAINER_PATH, 'data'))->toBe(0)
        ->and(preg_match(Grammar::ENV_NAME, 'DATABASE_URL'))->toBe(1)
        ->and(preg_match(Grammar::ENV_NAME, '1DATABASE'))->toBe(0)
        ->and(preg_match(Grammar::ENV_NAME, "KEY\n"))->toBe(0)
        ->and(preg_match(Grammar::PUBLIC_ID, 'prj_01JB7Z6X9Q3T8M5N2P4R6S8V0W'))->toBe(1)
        ->and(preg_match(Grammar::PUBLIC_ID, 'prj_81JB7Z6X9Q3T8M5N2P4R6S8V0W'))->toBe(0)
        ->and(preg_match('/^prj_'.Grammar::ULID_BODY.'$/D', 'prj_01JB7Z6X9Q3T8M5N2P4R6S8V0W'))->toBe(1);
});

it('exports every grammar, limit and constant of the vocabulary', function (): void {
    $vocabulary = protocolVocabulary();
    $grammar = new ReflectionClass(Grammar::class);
    $limits = new ReflectionClass(Limits::class);

    foreach (array_keys($vocabulary['grammars']) as $name) {
        expect($grammar->hasConstant(strtoupper(Str::snake($name))))->toBeTrue($name);
    }

    foreach ($vocabulary['limits'] as $name => $value) {
        expect($limits->getConstant(strtoupper(Str::snake($name))))->toBe($value, $name);
    }

    foreach ($vocabulary['constants'] as $name => $value) {
        expect($grammar->getConstant(strtoupper(Str::snake($name))))->toBe($value, $name);
    }

    expect(Grammar::FORBIDDEN_MOUNT_TARGETS)->toBe($vocabulary['forbiddenMountTargets'])->not->toBeEmpty()
        ->and(Limits::WAF_INSPECT_BODY_KIB)->toBe($vocabulary['intSets']['wafInspectBodyKib']);
});
