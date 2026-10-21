<?php

declare(strict_types=1);

namespace App\Support\Variables;

/**
 * Why a variable could not be resolved. Carries names only, never values.
 */
final readonly class ReferenceError
{
    public const string MISSING_SERVICE = 'missing_service';

    public const string MISSING_VARIABLE = 'missing_variable';

    public const string CYCLE = 'cycle';

    public const string INVALID_EXPRESSION = 'invalid_expression';

    /** A browser-inlined variable whose value embeds a secret; `expression` names the secret. */
    public const string SECRET_IN_PUBLIC_VALUE = 'secret_in_public_value';

    public function __construct(
        /** Service slug (or "shared") of the variable containing the reference. */
        public string $scope,
        public string $key,
        public string $expression,
        public string $reason,
    ) {}

    public function message(): string
    {
        $where = "{$this->scope}.{$this->key}";

        return match ($this->reason) {
            self::MISSING_SERVICE => "{$where}: {$this->expression} refers to a service that doesn't exist in this environment",
            self::MISSING_VARIABLE => "{$where}: {$this->expression} refers to a variable that doesn't exist",
            self::CYCLE => "{$where}: {$this->expression} is part of a reference cycle",
            self::SECRET_IN_PUBLIC_VALUE => "{$where} is baked into the public browser bundle, but its value references the secret {$this->expression}. Reference config values only",
            default => "{$where}: {$this->expression} is not a valid reference",
        };
    }
}
