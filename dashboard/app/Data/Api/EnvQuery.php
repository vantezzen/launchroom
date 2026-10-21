<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

/**
 * `GET …/env`: `secrets=1` asks for secret values too (needs `secrets:read`
 * and the reveal permission; otherwise they are listed in `withheld`).
 */
final class EnvQuery extends Data
{
    public function __construct(
        public bool $secrets = false,
    ) {}
}
