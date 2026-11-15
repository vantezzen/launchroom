<?php

declare(strict_types=1);

namespace App\Events\Backups;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A backup run reached a terminal status (docs/14 §7). Workflows waiting for
 * a snapshot (server moves, an import's safety backup) continue on it.
 */
final readonly class BackupRunFinished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $runId,
        public ?int $teamId,
    ) {}
}
