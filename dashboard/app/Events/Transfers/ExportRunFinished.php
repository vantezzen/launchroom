<?php

declare(strict_types=1);

namespace App\Events\Transfers;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A data export run is ready or failed (D55, docs/14 §12). Server moves
 * continue on it.
 */
final readonly class ExportRunFinished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $runId,
        public ?int $teamId,
    ) {}
}
