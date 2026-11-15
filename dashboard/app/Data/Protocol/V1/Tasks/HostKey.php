<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use Spatie\LaravelData\Data;

/**
 * One pinned SSH host key of a repository (protocol HostKey).
 */
final class HostKey extends Data
{
    public function __construct(
        public string $type,
        public string $key,
    ) {}
}
