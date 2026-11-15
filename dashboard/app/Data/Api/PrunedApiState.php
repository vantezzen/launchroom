<?php

declare(strict_types=1);

namespace App\Data\Api;

final readonly class PrunedApiState
{
    public function __construct(
        public int $logins,
        public int $idempotencyKeys,
        public int $tokens,
    ) {}
}
