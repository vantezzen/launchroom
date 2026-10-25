<?php

declare(strict_types=1);

use App\Support\Variables\GeneratedValues;
use App\Support\Variables\VariableReferences;

/**
 * @return array{array<string, mixed>, array<string, mixed>, string} header, payload, signature (decoded)
 */
function decodeJwt(string $token): array
{
    $parts = explode('.', $token);
    expect($parts)->toHaveCount(3);
    $decode = static fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/'), true);

    return [
        json_decode($decode($parts[0]), true, 4, JSON_THROW_ON_ERROR),
        json_decode($decode($parts[1]), true, 4, JSON_THROW_ON_ERROR),
        $decode($parts[2]),
    ];
}

test('jwt() signs an HS256 token whose signature verifies with the secret', function (): void {
    $secret = str_repeat('s3cr3t-', 7);
    $token = GeneratedValues::jwt('anon', $secret, 1_800_000_000);
    [$header, $payload, $signature] = decodeJwt($token);
    [$signed] = explode('.', $token, 3);
    $signingInput = substr($token, 0, (int) strrpos($token, '.'));

    expect($header)->toBe(['alg' => 'HS256', 'typ' => 'JWT'])
        ->and($payload)->toBe(['role' => 'anon', 'iss' => 'supabase', 'iat' => 1_800_000_000, 'exp' => 1_800_000_000 + GeneratedValues::JWT_LIFETIME_SECONDS])
        ->and(hash_equals(hash_hmac('sha256', $signingInput, $secret, true), $signature))->toBeTrue()
        // Another secret doesn't verify it.
        ->and(hash_equals(hash_hmac('sha256', $signingInput, $secret.'x', true), $signature))->toBeFalse()
        ->and($signed)->not->toContain('=');
});

test('jwt() refuses short secrets and odd roles', function (): void {
    expect(fn (): string => GeneratedValues::jwt('anon', 'short'))->toThrow(InvalidArgumentException::class, 'at least 32')
        ->and(fn (): string => GeneratedValues::jwt('Anon Role', str_repeat('x', 40)))->toThrow(InvalidArgumentException::class);
});

test('jwt expressions are generators signed with another variable', function (): void {
    $value = '${{ jwt("service_role", JWT_SECRET) }}';
    $secret = str_repeat('k', 48);

    expect(GeneratedValues::contains($value))->toBeTrue()
        ->and(GeneratedValues::sole($value))->toBe('jwt("service_role",JWT_SECRET)')
        ->and(GeneratedValues::signingKey(GeneratedValues::sole($value)))->toBe('JWT_SECRET')
        ->and(GeneratedValues::jwtRole('jwt("service_role",JWT_SECRET)'))->toBe('service_role')
        ->and(GeneratedValues::signingKey('secret(32)'))->toBeNull()
        ->and(VariableReferences::invalid($value))->toBe([])
        ->and(VariableReferences::invalid($value, allowFunctions: false))->toBe([$value]);

    $asked = [];
    $token = GeneratedValues::evaluate($value, function (string $key) use (&$asked, $secret): string {
        $asked[] = $key;

        return $secret;
    });
    [, $payload] = decodeJwt($token);

    expect($asked)->toBe(['JWT_SECRET'])
        ->and($payload['role'])->toBe('service_role')
        ->and(GeneratedValues::fresh('jwt("service_role",JWT_SECRET)', fn (): string => $secret))->toMatch('/^[\w-]+\.[\w-]+\.[\w-]+$/');
});

test('a jwt without its signing secret is an error, never an unsigned value', function (): void {
    expect(fn (): string => GeneratedValues::evaluate('${{ jwt("anon", JWT_SECRET) }}'))->toThrow(InvalidArgumentException::class, 'create JWT_SECRET')
        ->and(fn (): string => GeneratedValues::evaluate('${{ jwt("anon", JWT_SECRET) }}', fn (): ?string => null))->toThrow(InvalidArgumentException::class);
});
