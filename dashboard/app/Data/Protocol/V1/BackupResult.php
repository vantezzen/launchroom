<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * TaskCompletion.backup (protocol/schema/v1/task-completion.json).
 */
final class BackupResult extends Data
{
    public function __construct(
        #[Regex('/^[a-f0-9]{8,64}$/D')]
        public string $snapshotId,
        #[Min(0)]
        public int $sizeBytes,
        #[Min(0)]
        public int $durationMs,
        #[Min(0)]
        public int|Optional $addedBytes = new Optional,
    ) {}
}
