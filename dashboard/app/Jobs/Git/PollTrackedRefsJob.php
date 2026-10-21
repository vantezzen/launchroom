<?php

declare(strict_types=1);

namespace App\Jobs\Git;

use App\Actions\Git\PollStaleTrackedRefs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Scheduled every minute: the polling fallback (docs/06 §2).
 */
#[Timeout(55)]
#[Tries(1)]
#[UniqueFor(60)]
final class PollTrackedRefsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(PollStaleTrackedRefs $poll): void
    {
        $poll->handle();
    }
}
