<?php

declare(strict_types=1);

use App\Support\Variables\GeneratedValues;
use App\Support\Variables\VariableReferences;

test('parses reference names', function (): void {
    expect(VariableReferences::names('${{postgres.DATABASE_URL}} ${{shared.KEY}} ${{ SELF }} ${{postgres.DATABASE_URL}}'))
        ->toBe(['postgres.DATABASE_URL', 'shared.KEY', 'SELF']);
});

test('lists invalid expressions but accepts generator functions', function (): void {
    expect(VariableReferences::invalid('${{ secret(32) }} ${{ uuid() }} ${{ nope( }} ${{a b}}'))
        ->toBe(['${{ nope( }}', '${{a b}}']);
});

test('generates alphanumeric secrets of the requested length', function (): void {
    $value = GeneratedValues::evaluate('${{ secret(32) }}');

    expect($value)->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and(GeneratedValues::evaluate('${{ secret(32) }}'))->not->toBe($value);
});

test('generates encoded secrets and uuids', function (): void {
    expect(strlen((string) base64_decode(GeneratedValues::evaluate('${{ secret(64, "base64") }}'), true)))->toBe(64)
        ->and(GeneratedValues::evaluate('${{secret(16,"hex")}}'))->toMatch('/^[a-f0-9]{32}$/')
        ->and(GeneratedValues::evaluate('${{ secret(16, "base64url") }}'))->toMatch('/^[A-Za-z0-9_-]{22}$/')
        ->and(GeneratedValues::evaluate('${{ uuid() }}'))->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

test('evaluates generators inside larger values and leaves references alone', function (): void {
    $value = GeneratedValues::evaluate('base64:${{ secret(32, "base64") }} for ${{api.URL}}');

    expect($value)->toStartWith('base64:')
        ->and($value)->toEndWith(' for ${{api.URL}}')
        ->and(GeneratedValues::contains('x ${{ uuid() }}'))->toBeTrue()
        ->and(GeneratedValues::contains('${{api.URL}}'))->toBeFalse();
});

test('rejects unsafe lengths and unknown encodings', function (string $expression): void {
    expect(fn (): string => GeneratedValues::evaluate($expression))->toThrow(InvalidArgumentException::class);
})->with(['${{ secret(4) }}', '${{ secret(5000) }}', '${{ secret(32, "rot13") }}']);
