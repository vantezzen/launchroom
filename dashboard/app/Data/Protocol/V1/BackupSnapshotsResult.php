<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * TaskCompletion.snapshots (protocol/schema/v1/task-completion.json).
 */
final class BackupSnapshotsResult extends Data
{
    /**
     * @param  list<SnapshotInfo>  $snapshots
     */
    public function __construct(
        #[Max(1000)]
        public array $snapshots,
        public bool|Optional $truncated = new Optional,
    ) {}
}
