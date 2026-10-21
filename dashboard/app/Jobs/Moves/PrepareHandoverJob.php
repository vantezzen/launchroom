<?php

declare(strict_types=1);

namespace App\Jobs\Moves;

use App\Actions\Moves\PrepareHandover;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;

/**
 * The final dump of a control-plane hand-over (docs/14 §13.4), off the
 * request path. No retries: a failure thaws the dashboard and the owner
 * starts the hand-over again.
 */
#[Tries(1)]
final class PrepareHandoverJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout;

    public function __construct(
        public readonly int $moveId,
    ) {
        $this->timeout = config()->integer('launchroom.backups.task_timeout_seconds');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->moveId;
    }

    public function handle(PrepareHandover $prepare): void
    {
        $prepare->handle($this->moveId);
    }
}
