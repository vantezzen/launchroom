<?php

declare(strict_types=1);

namespace App\Jobs\Transfers;

use App\Actions\Transfers\AdvanceImports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Moves imports on now instead of at the next transfers tick.
 */
#[Backoff([5, 30])]
#[Timeout(60)]
#[Tries(3)]
final class AdvanceImportsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function handle(AdvanceImports $advance): void
    {
        $advance->handle();
    }
}
