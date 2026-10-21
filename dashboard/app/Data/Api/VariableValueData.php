<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

final class VariableValueData extends Data
{
    public function __construct(
        public string $key,
        public string $value,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['key' => $this->key, 'value' => '[redacted]'];
    }
}
