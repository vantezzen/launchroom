<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Support\Crypto\OpaqueToken;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

final class ClaimCliTokenInput extends Data
{
    public function __construct(
        #[Regex('/^'.OpaqueToken::BODY.'$/D')]
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
