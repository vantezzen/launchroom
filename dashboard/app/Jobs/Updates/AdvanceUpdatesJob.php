<?php

declare(strict_types=1);

namespace App\Jobs\Updates;

use App\Actions\Updates\AdvanceUpdates;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Advances running updates after a self_update task finished (docs/11 §4).
 * Idempotent; the scheduler runs the same action every minute.
 */
#[Tries(3)]
#[Backoff(5)]
#[Timeout(60)]
final class AdvanceUpdatesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function handle(AdvanceUpdates $action): void
    {
        $action->handle();
    }
}
