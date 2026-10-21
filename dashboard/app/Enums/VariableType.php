<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Variable types (docs/06 §3.1): config values are readable by anyone who can
 * see the service; secrets are write-only after saving.
 */
enum VariableType: string
{
    case Config = 'config';
    case Secret = 'secret';

    /**
     * Imported keys matching secret patterns default to secret (docs/06 §3.1):
     * `*_KEY`, `*_SECRET`, `*_TOKEN`, `*PASSWORD*`, `*_DSN`. Browser-inlined
     * keys can never be secret (docs/06 §3.3).
     */
    public static function suggestFor(string $key): self
    {
        if (VariableExposure::isBrowserInlined($key)) {
            return self::Config;
        }

        $upper = strtoupper($key);

        foreach (['_KEY', '_SECRET', '_TOKEN', '_DSN'] as $suffix) {
            if (str_ends_with($upper, $suffix)) {
                return self::Secret;
            }
        }

        return str_contains($upper, 'PASSWORD') || in_array($upper, ['SECRET', 'TOKEN', 'DSN'], true)
            ? self::Secret
            : self::Config;
    }
}
