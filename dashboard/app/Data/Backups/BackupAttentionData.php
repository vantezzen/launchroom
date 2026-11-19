<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Data;

/**
 * An item the headline points at (failed or overdue).
 */
final class BackupAttentionData extends Data
{
    public function __construct(
        /** The row's id in the groups. */
        public string $id,
        public string $name,
    ) {}
}
