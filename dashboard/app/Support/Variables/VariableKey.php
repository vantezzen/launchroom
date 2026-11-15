<?php

declare(strict_types=1);

namespace App\Support\Variables;

use App\Support\Protocol\Grammar;
use App\Support\Protocol\Limits;

/**
 * The environment variable key grammar and value cap, as launchroomd accepts
 * them in a workload's env (protocol vocabulary `envName`, `envValueBytes`).
 */
final class VariableKey
{
    public const string PATTERN = Grammar::ENV_NAME;

    /** Unanchored, for composing into larger patterns. */
    public const string BODY = Grammar::ENV_NAME_BODY;

    public const int MAX_VALUE_BYTES = Limits::ENV_VALUE_BYTES;

    public static function isValid(string $key): bool
    {
        return preg_match(self::PATTERN, $key) === 1;
    }
}
