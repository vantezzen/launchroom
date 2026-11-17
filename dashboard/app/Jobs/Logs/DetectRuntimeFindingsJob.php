<?php

declare(strict_types=1);

namespace App\Jobs\Logs;

use App\Actions\Logs\DetectRuntimeFindings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Reads a server's new runtime logs for known problems (G12). At most one
 * per server every 30 seconds: log batches arrive every few seconds, and
 * the action reads everything stored since its last run.
 */
#[Backoff([10, 60])]
#[Timeout(60)]
#[Tries(2)]
#[UniqueFor(30)]
final class DetectRuntimeFindingsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $serverId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->serverId;
    }

    public function handle(DetectRuntimeFindings $detect): void
    {
        $detect->handle($this->serverId);
    }
}
