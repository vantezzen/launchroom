<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Models\Backups\BackupRun;
use App\Support\Refusal;

/**
 * What a safety snapshot started (App\Actions\Backups\TakeSafetySnapshot):
 * one run for a database, one per volume otherwise, and why each one that
 * didn't start was skipped.
 */
final readonly class SafetySnapshot
{
    /**
     * @param  list<BackupRun>  $runs
     * @param  list<Refusal>  $skipped
     */
    public function __construct(
        public array $runs = [],
        public array $skipped = [],
    ) {}

    public function first(): ?BackupRun
    {
        return $this->runs[0] ?? null;
    }
}
