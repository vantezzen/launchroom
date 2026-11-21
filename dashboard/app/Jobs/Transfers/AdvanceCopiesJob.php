<?php

declare(strict_types=1);

namespace App\Jobs\Transfers;

use App\Actions\Transfers\AdvanceCopies;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Moves copies between environments on right after an export or import
 * finished, instead of at the next transfers tick (docs/14 §14.2).
 * Idempotent; one queued at a time.
 */
#[Backoff([5, 30])]
#[Timeout(120)]
#[Tries(3)]
final class AdvanceCopiesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function handle(AdvanceCopies $copies): void
    {
        $copies->handle();
    }
}
