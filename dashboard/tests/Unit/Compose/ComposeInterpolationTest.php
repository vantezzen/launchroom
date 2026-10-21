<?php

declare(strict_types=1);

use App\Support\Compose\ComposeInterpolation;
use App\Support\Compose\InterpolationFailed;

test('substitution follows the Compose rules', function (string $value, string $expected): void {
    $interpolation = new ComposeInterpolation(['SET' => 'value', 'EMPTY' => '', 'PORT' => '8080']);

    expect($interpolation->substitute($value))->toBe($expected);
})->with([
    ['${SET}', 'value'],
    ['$SET/path', 'value/path'],
    ['${UNSET:-fallback}', 'fallback'],
    ['${EMPTY:-fallback}', 'fallback'],
    ['${EMPTY-fallback}', ''],
    ['${UNSET-fallback}', 'fallback'],
    ['${SET:+alt}', 'alt'],
    ['${UNSET:+alt}', ''],
    ['${EMPTY+alt}', 'alt'],
    ['${UNSET:-${PORT}}', '8080'],
    ['${UNSET:-${ALSO_UNSET:-deep}}', 'deep'],
    ['price: $$5 and $$SET', 'price: $5 and $SET'],
    ['${UNSET}', ''],
    ['trailing $', 'trailing $'],
    ['127.0.0.1:${PORT}:80', '127.0.0.1:8080:80'],
]);

test('required values and generated secrets can’t be substituted outside variables', function (string $value, string $message): void {
    expect(fn (): string => (new ComposeInterpolation)->substitute($value))->toThrow(InterpolationFailed::class, $message);
})->with([
    ['${NEEDED:?set NEEDED first}', 'is required: set NEEDED first'],
    ['${DB_PASSWORD}', 'generated secret'],
    ['${BROKEN', 'never closed'],
    ['${1BAD}', 'needs a variable name'],
    ['${NAME:x}', 'Invalid interpolation'],
]);

test('in variables, names become shared references and secrets nobody set are generated', function (): void {
    $interpolation = new ComposeInterpolation(['GIVEN' => 'yes']);

    expect($interpolation->forVariable('postgres://app:${DB_PASSWORD}@db/app'))->toBe('postgres://app:${{shared.DB_PASSWORD}}@db/app')
        ->and($interpolation->forVariable('${GIVEN}'))->toBe('${{shared.GIVEN}}')
        ->and($interpolation->forVariable('${HOSTNAME_PUBLIC:-example.com}'))->toBe('example.com')
        ->and($interpolation->forVariable('${SESSION_SECRET:-changeme}'))->toBe('${{shared.SESSION_SECRET}}')
        ->and($interpolation->forVariable('$${{literal}}'))->toBe('${{literal}}');

    $names = $interpolation->names();

    expect($names['DB_PASSWORD'])->toMatchArray(['provided' => false, 'generated' => true, 'variable' => true])
        ->and($names['GIVEN'])->toMatchArray(['provided' => true, 'generated' => false])
        ->and($names['SESSION_SECRET']['generated'])->toBeTrue();
});
