<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

/**
 * The answer to creating or rotating a robot token: the plaintext, once
 * (docs/16 §1.4). Never flashed into the session.
 */
final class CreatedRobotTokenData extends Data
{
    public function __construct(
        public RobotTokenData $token,
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
