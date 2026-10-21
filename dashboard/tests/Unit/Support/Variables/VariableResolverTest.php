<?php

declare(strict_types=1);

use App\Exceptions\UnresolvedReferences;
use App\Support\Variables\ReferenceError;
use App\Support\Variables\VariableResolver;

/**
 * @param  array<string, array<string, string>>  $scopes
 * @return list<string>
 */
function referenceErrors(array $scopes, string $scope): array
{
    try {
        new VariableResolver($scopes)->resolve($scope);
    } catch (UnresolvedReferences $e) {
        return array_map(static fn (ReferenceError $error): string => "{$error->scope}.{$error->key}:{$error->expression}:{$error->reason}", $e->errors);
    }

    return [];
}

test('resolves same-service, cross-service and shared references', function (): void {
    $resolver = new VariableResolver([
        'shared' => ['REGION' => 'eu'],
        'postgres' => ['POSTGRES_USER' => 'app', 'POSTGRES_PASSWORD' => 's3cret', 'PRIVATE_HOST' => 'postgres', 'DATABASE_URL' => 'postgresql://${{POSTGRES_USER}}:${{POSTGRES_PASSWORD}}@${{PRIVATE_HOST}}:5432/app'],
        'api' => ['DATABASE_URL' => '${{postgres.DATABASE_URL}}', 'REGION' => '${{shared.REGION}}', 'GREETING' => 'hi from ${{REGION}}'],
    ]);

    expect($resolver->resolve('api')->all())->toBe([
        'DATABASE_URL' => 'postgresql://app:s3cret@postgres:5432/app',
        'REGION' => 'eu',
        'GREETING' => 'hi from eu',
    ]);
});

test('whitespace inside the braces is allowed', function (): void {
    expect(new VariableResolver(['web' => ['A' => 'x', 'B' => '${{ A }}']])->resolve('web')->get('B'))->toBe('x');
});

test('values are verbatim: substituted text is not re-scanned and $VAR is never expanded', function (): void {
    $resolver = new VariableResolver([
        'a' => ['LITERAL' => '$HOME and ${PATH}', 'TRICKY' => '${{b.TEMPLATE}}'],
        // B's own references are resolved in B's scope ...
        'b' => ['X' => 'b-x', 'TEMPLATE' => '${{X}}'],
    ]);

    expect($resolver->resolve('a')->all())->toBe(['LITERAL' => '$HOME and ${PATH}', 'TRICKY' => 'b-x']);
});

test('a value containing a literal reference-looking result is not interpreted twice', function (): void {
    // b.RAW resolves to text that *looks* like an expression; it must stay as text.
    $resolver = new VariableResolver(['a' => ['V' => '${{b.WRAP}}'], 'b' => ['RAW' => '$', 'WRAP' => '${{RAW}}{{a.V}}']]);

    expect($resolver->resolve('a')->get('V'))->toBe('${{a.V}}');
});

test('missing services and variables are reported, never kept as literals', function (): void {
    $errors = referenceErrors(['api' => ['A' => '${{redis.REDIS_URL}}', 'B' => '${{MISSING}}', 'C' => '${{shared.NOPE}}'], 'shared' => []], 'api');

    expect($errors)->toBe([
        'api.A:${{redis.REDIS_URL}}:missing_service',
        'api.B:${{MISSING}}:missing_variable',
        'api.C:${{shared.NOPE}}:missing_variable',
    ]);
});

test('detects direct, indirect and cross-service cycles', function (): void {
    expect(referenceErrors(['a' => ['X' => '${{X}}']], 'a'))->toBe(['a.X:${{X}}:cycle'])
        ->and(referenceErrors(['a' => ['X' => '${{Y}}', 'Y' => '${{X}}']], 'a'))->toContain('a.Y:${{X}}:cycle')
        ->and(referenceErrors(['a' => ['X' => '${{b.Y}}'], 'b' => ['Y' => '${{a.X}}']], 'a'))->toContain('b.Y:${{a.X}}:cycle');
});

test('rejects malformed expressions', function (string $expression): void {
    expect(referenceErrors(['a' => ['X' => "pre {$expression} post"]], 'a'))->toBe(["a.X:{$expression}:invalid_expression"]);
})->with([
    '${{}}',
    '${{ foo bar }}',
    '${{Api.KEY}}',
    '${{a.b.c}}',
    '${{1KEY}}',
    '${{ secret(32) }}', // generated values are evaluated at creation, never at resolution
]);

test('reports every broken reference at once and dependants fail silently', function (): void {
    try {
        new VariableResolver([
            'web' => ['A' => '${{api.X}}', 'B' => '${{A}}'],
            'api' => ['X' => '${{db.URL}}'],
        ])->resolveAll();
        $this->fail('expected UnresolvedReferences');
    } catch (UnresolvedReferences $e) {
        expect($e->errors)->toHaveCount(1)
            ->and($e->getMessage())->toContain('api.X: ${{db.URL}} refers to a service that doesn\'t exist');
    }
});

test('resolveAll can be restricted to some scopes', function (): void {
    $resolver = new VariableResolver(['ok' => ['A' => '1'], 'broken' => ['B' => '${{nope.X}}']]);

    expect(array_keys($resolver->resolveAll(['ok'])))->toBe(['ok']);
});

test('the shared scope is not returned by resolveAll', function (): void {
    expect(array_keys(new VariableResolver(['shared' => ['A' => '1'], 'web' => ['B' => '2']])->resolveAll()))->toBe(['web']);
});

test('shared variables resolve their own references in the shared scope', function (): void {
    $resolver = new VariableResolver(['shared' => ['HOST' => 'example.com', 'URL' => 'https://${{HOST}}'], 'web' => ['U' => '${{shared.URL}}']]);

    expect($resolver->resolve('web')->get('U'))->toBe('https://example.com');
});

test('resolved environments never print their values', function (): void {
    $env = new VariableResolver(['web' => ['SECRET' => 'hunter2']])->resolve('web');

    expect(print_r($env, true))->not->toContain('hunter2')
        ->and(fn (): string => serialize($env))->toThrow(LogicException::class);
});

test('a property-style check: random reference graphs either resolve completely or fail with errors', function (): void {
    mt_srand(42);

    for ($run = 0; $run < 200; $run++) {
        $scopes = [];
        $slugs = ['a', 'b', 'c'];

        foreach ($slugs as $slug) {
            foreach (['X', 'Y', 'Z'] as $key) {
                $value = 'v';

                for ($i = 0; $i < mt_rand(0, 2); $i++) {
                    $value .= '${{'.$slugs[mt_rand(0, 2)].'.'.['X', 'Y', 'Z', 'W'][mt_rand(0, 3)].'}}';
                }

                $scopes[$slug][$key] = $value;
            }
        }

        try {
            foreach (new VariableResolver($scopes)->resolveAll() as $env) {
                foreach ($env->all() as $value) {
                    expect($value)->not->toContain('${{');
                }
            }
        } catch (UnresolvedReferences $e) {
            expect($e->errors)->not->toBeEmpty();
        }
    }
});
