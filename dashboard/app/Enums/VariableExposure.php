<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a variable is visible: at runtime, at build time, or both
 * (docs/06 §3.3). Computed from the key; overridable per variable.
 */
enum VariableExposure: string
{
    case Runtime = 'runtime';
    case Build = 'build';
    case Both = 'both';

    /**
     * Framework-inlined prefixes: values are baked into browser bundles at
     * build time (and readable by anyone), so they are build+runtime and can
     * never be secrets.
     */
    public const array BROWSER_INLINED_PREFIXES = ['NEXT_PUBLIC_', 'VITE_', 'PUBLIC_', 'REACT_APP_', 'EXPO_PUBLIC_', 'GATSBY_'];

    /** Prefixes that stay runtime-only although they look public (Nitro reads them at runtime). */
    public const array RUNTIME_PREFIXES = ['NUXT_PUBLIC_'];

    /** Build tooling that reads these during the build. */
    public const array BUILD_TOOLING_KEYS = [
        'SENTRY_AUTH_TOKEN', 'SENTRY_ORG', 'SENTRY_PROJECT',
        'NODE_OPTIONS', 'NPM_TOKEN', 'COMPOSER_AUTH', 'PIP_INDEX_URL', 'UV_INDEX_URL',
        // launchroom-generated build keys (docs/04 §3, Next.js preset).
        'NEXT_SERVER_ACTIONS_ENCRYPTION_KEY',
    ];

    public const array BUILD_TOOLING_PREFIXES = ['NPM_CONFIG_', 'BUNDLE_'];

    public static function classify(string $key): self
    {
        $upper = strtoupper($key);

        foreach (self::RUNTIME_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return self::Runtime;
            }
        }

        if (self::isBrowserInlined($key) || in_array($upper, self::BUILD_TOOLING_KEYS, true)) {
            return self::Both;
        }

        foreach (self::BUILD_TOOLING_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return self::Both;
            }
        }

        return self::Runtime;
    }

    /**
     * The effective exposure: the override if set, else the classification.
     */
    public static function effective(string $key, ?self $override): self
    {
        return $override ?? self::classify($key);
    }

    public static function isBrowserInlined(string $key): bool
    {
        $upper = strtoupper($key);

        foreach (self::RUNTIME_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return false;
            }
        }

        return array_any(self::BROWSER_INLINED_PREFIXES, fn (string $prefix): bool => str_starts_with($upper, $prefix));
    }

    public function includesBuild(): bool
    {
        return $this !== self::Runtime;
    }

    public function includesRuntime(): bool
    {
        return $this !== self::Build;
    }
}
