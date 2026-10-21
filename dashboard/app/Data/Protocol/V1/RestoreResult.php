<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * TaskCompletion.restore (protocol/schema/v1/task-completion.json).
 */
final class RestoreResult extends Data
{
    public function __construct(
        #[Min(0)]
        public int $sizeBytes,
        #[Min(0)]
        public int $durationMs,
        /** base_backup restores: the last transaction replayed (docs/14 §10.3). */
        #[Regex(Report::TIME_PATTERN)]
        public string|null|Optional $restoredTo = new Optional,
    ) {}
}
