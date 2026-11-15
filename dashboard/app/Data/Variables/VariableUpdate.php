<?php

declare(strict_types=1);

namespace App\Data\Variables;

use App\Data\Attributes\PublicIdOf;
use App\Enums\VariableExposure;
use App\Enums\VariableType;
use App\Support\Variables\VariableKey;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Change a variable. Omitted fields stay as they are; a new value bumps the
 * version (and is re-encrypted under the new associated data).
 */
final class VariableUpdate extends Data
{
    /**
     * @param  list<VariableTargetData>|Optional  $targets
     */
    public function __construct(
        #[Regex(VariableKey::PATTERN)]
        public string|Optional $key = new Optional,
        #[Max(VariableKey::MAX_VALUE_BYTES)]
        public string|Optional $value = new Optional,
        public VariableType|Optional $type = new Optional,
        public array|Optional $targets = new Optional,
        public VariableExposure|null|Optional $exposureOverride = new Optional,
        /**
         * Apply the edit to this environment only: a row that also targets
         * other environments is split (docs/06 §3.1 "when values diverge").
         */
        #[PublicIdOf('env')]
        public string|Optional $onlyEnvironment = new Optional,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }
}
