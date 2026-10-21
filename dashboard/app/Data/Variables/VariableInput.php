<?php

declare(strict_types=1);

namespace App\Data\Variables;

use App\Enums\VariableExposure;
use App\Enums\VariableType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Create a variable (docs/06 §3). The value is stored verbatim; generator
 * functions (`${{ secret(32) }}`) are evaluated once on save.
 */
final class VariableInput extends Data
{
    /**
     * @param  list<VariableTargetData>  $targets
     */
    public function __construct(
        #[Regex('/^[A-Za-z_][A-Za-z0-9_]{0,255}$/D')]
        public string $key,
        #[Max(131072)]
        public string $value,
        public VariableType $type,
        #[Min(1)]
        public array $targets,
        public ?VariableExposure $exposureOverride = null,
    ) {}

    /**
     * Never echo values back (validation errors, debugging).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['key' => $this->key, 'value' => '[redacted]', 'type' => $this->type];
    }
}
