<?php

declare(strict_types=1);

use App\Enums\VariableExposure;
use App\Enums\VariableType;

test('classifies keys by the docs/06 §3.3 tables', function (string $key, VariableExposure $expected): void {
    expect(VariableExposure::classify($key))->toBe($expected);
})->with([
    ['NEXT_PUBLIC_API_URL', VariableExposure::Both],
    ['VITE_APP_TITLE', VariableExposure::Both],
    ['PUBLIC_SITE', VariableExposure::Both],
    ['REACT_APP_X', VariableExposure::Both],
    ['EXPO_PUBLIC_X', VariableExposure::Both],
    ['GATSBY_X', VariableExposure::Both],
    ['SENTRY_AUTH_TOKEN', VariableExposure::Both],
    ['SENTRY_ORG', VariableExposure::Both],
    ['SENTRY_PROJECT', VariableExposure::Both],
    ['NODE_OPTIONS', VariableExposure::Both],
    ['NPM_TOKEN', VariableExposure::Both],
    ['NPM_CONFIG_REGISTRY', VariableExposure::Both],
    ['COMPOSER_AUTH', VariableExposure::Both],
    ['PIP_INDEX_URL', VariableExposure::Both],
    ['UV_INDEX_URL', VariableExposure::Both],
    ['BUNDLE_GEMFILE', VariableExposure::Both],
    ['NEXT_SERVER_ACTIONS_ENCRYPTION_KEY', VariableExposure::Both],
    ['AUTH_SECRET', VariableExposure::Both],
    ['NEXTAUTH_SECRET', VariableExposure::Both],
    // Nitro reads NUXT_PUBLIC_* at runtime.
    ['NUXT_PUBLIC_API', VariableExposure::Runtime],
    ['DATABASE_URL', VariableExposure::Runtime],
    ['SENTRY_DSN', VariableExposure::Runtime],
    ['next_public_lowercase', VariableExposure::Both],
]);

test('overrides win over the classification', function (): void {
    expect(VariableExposure::effective('DATABASE_URL', VariableExposure::Build))->toBe(VariableExposure::Build)
        ->and(VariableExposure::effective('DATABASE_URL', null))->toBe(VariableExposure::Runtime)
        ->and(VariableExposure::Both->includesBuild())->toBeTrue()
        ->and(VariableExposure::Build->includesRuntime())->toBeFalse();
});

test('browser-inlined keys are never secrets', function (): void {
    expect(VariableExposure::isBrowserInlined('NEXT_PUBLIC_X'))->toBeTrue()
        ->and(VariableExposure::isBrowserInlined('NUXT_PUBLIC_X'))->toBeFalse()
        ->and(VariableType::suggestFor('NEXT_PUBLIC_SECRET_KEY'))->toBe(VariableType::Config);
});
