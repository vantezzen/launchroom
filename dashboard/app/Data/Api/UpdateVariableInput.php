<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\VariableType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Change a variable in this environment only (a row that also targets other
 * environments is split, docs/06 §3.1). Omitted fields stay.
 */
final class UpdateVariableInput extends Data
{
    public function __construct(
        #[Regex('/^[A-Za-z_][A-Za-z0-9_]{0,255}$/D')]
        public string|Optional $key = new Optional,
        #[Max(131072)]
        public string|Optional $value = new Optional,
        public VariableType|Optional $type = new Optional,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }
}
