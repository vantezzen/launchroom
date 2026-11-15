<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use Spatie\LaravelData\Data;

/**
 * What to back up or restore into (protocol BackupTarget): exactly the
 * fields of the kind are set.
 */
final class BackupTarget extends Data
{
    public function __construct(
        public ?string $engine = null,
        public ?string $workload = null,
        public ?string $volume = null,
        public ?string $consistency = null,
    ) {}
}
