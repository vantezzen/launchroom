<?php

declare(strict_types=1);

use App\Services\GitHub\InstallationToken;
use App\Services\GitHub\TokenScope;
use App\Support\Git\GitRef;
use App\Support\Git\SkipMarkers;
use Carbon\CarbonImmutable;

test('skip markers are honoured case-insensitively', function (string $message, bool $skip): void {
    expect(SkipMarkers::matches($message))->toBe($skip);
})->with([
    ['Fix typo [skip ci]', true],
    ["Bump deps\n\n[SKIP DEPLOY]", true],
    ['[ci skip] docs', true],
    ['[no ci]', true],
    ['[deploy skip]', true],
    ['skip ci without brackets', false],
    ['Deploy the thing', false],
]);

test('refs follow a conservative grammar', function (string $ref, bool $valid): void {
    expect(GitRef::isValid($ref))->toBe($valid);
})->with([
    ['refs/heads/main', true],
    ['refs/heads/feature/new-ui', true],
    ['refs/tags/v1.2.0', true],
    ['main', false],
    ['refs/heads/../../etc', false],
    ['refs/heads/a b', false],
    ['refs/heads/x;id', false],
    ['refs/heads/--upload-pack', true], // never reaches a CLI as an option: argv + `--` (docs/09 R2)
    ['refs/pull/1/head', false],
    ['refs/heads/x.lock', false],
]);

test('installation tokens never print or serialize their value', function (): void {
    $token = new InstallationToken('ghs_secret', CarbonImmutable::now()->addHour(), [1], ['contents' => 'read']);

    expect(print_r($token, true))->not->toContain('ghs_secret')
        ->and(fn (): string => serialize($token))->toThrow(LogicException::class)
        ->and($token->reveal())->toBe('ghs_secret');
});

test('token scopes narrow to one repository and minimal permissions', function (): void {
    expect(TokenScope::clone(7)->requestBody())->toBe([
        'permissions' => ['contents' => 'read', 'metadata' => 'read'],
        'repository_ids' => [7],
    ])
        ->and(TokenScope::report(7)->requestBody()['permissions'])->not->toHaveKey('contents')
        ->and(TokenScope::listRepositories()->requestBody())->toBe(['permissions' => ['metadata' => 'read']])
        ->and(TokenScope::clone(7)->cacheKey())->not->toBe(TokenScope::clone(8)->cacheKey());
});
