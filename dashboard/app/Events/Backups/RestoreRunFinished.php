<?php

declare(strict_types=1);

namespace App\Events\Backups;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A restore run reached a terminal status (docs/14 §5). Previews cloning a
 * database continue on it.
 */
final readonly class RestoreRunFinished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $runId,
        public ?int $teamId,
    ) {}
}
