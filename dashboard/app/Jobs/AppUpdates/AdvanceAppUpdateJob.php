<?php

declare(strict_types=1);

namespace App\Jobs\AppUpdates;

use App\Actions\AppUpdates\AdvanceAppUpdate;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Moves one app update along right after something it waits for changed
 * (docs/04 §14.2). Idempotent; the minute tick runs the same action.
 */
#[Tries(3)]
#[Backoff([5, 30])]
#[Timeout(120)]
final class AdvanceAppUpdateJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct(public int $updateId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->updateId;
    }

    public function handle(AdvanceAppUpdate $advance): void
    {
        $advance->handle($this->updateId);
    }
}
