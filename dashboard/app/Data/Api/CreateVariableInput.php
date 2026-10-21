<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Data\Variables\VariableTargetData;
use App\Enums\VariableType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Create a variable for the service (staged until deploy). Without
 * `targets` it applies to the environment in the path only.
 */
final class CreateVariableInput extends Data
{
    /**
     * @param  list<VariableTargetData>|Optional  $targets
     */
    public function __construct(
        #[Regex('/^[A-Za-z_][A-Za-z0-9_]{0,255}$/D')]
        public string $key,
        #[Max(131072)]
        public string $value,
        public VariableType $type = VariableType::Config,
        public array|Optional $targets = new Optional,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['key' => $this->key, 'value' => '[redacted]', 'type' => $this->type];
    }
}
