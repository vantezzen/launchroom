<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

/**
 * A token just created in Settings → API tokens: the plaintext is in this
 * one response body and nowhere else (docs/16 §1.1).
 */
final class CreatedApiTokenData extends Data
{
    public function __construct(
        public ApiTokenData $token,
        public string $plainText,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['token' => $this->token, 'plainText' => '[redacted]'];
    }
}
