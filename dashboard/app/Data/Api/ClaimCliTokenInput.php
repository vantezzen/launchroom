<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

final class ClaimCliTokenInput extends Data
{
    public function __construct(
        #[Regex('/^[A-Za-z0-9_-]{43}$/D')]
        public string $deviceCode,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['deviceCode' => '[redacted]'];
    }
}
