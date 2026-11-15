<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use Spatie\LaravelData\Data;

/**
 * The owner of imported files (protocol FileOwner).
 */
final class FileOwner extends Data
{
    public function __construct(
        public int $uid,
        public int $gid,
    ) {}
}
