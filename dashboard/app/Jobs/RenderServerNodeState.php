<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Servers\RenderNodeState;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Re-renders one server's NodeState (debounced: unique per server until it
 * starts processing, so invalidations during a render queue exactly one more).
 * Idempotent: unchanged content keeps the version.
 */
#[Backoff([1, 5])]
#[Timeout(60)]
#[Tries(3)]
#[UniqueFor(60)]
final class RenderServerNodeState implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $serverId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->serverId;
    }

    public function handle(RenderNodeState $renderNodeState): void
    {
        $server = Server::query()->whereKey($this->serverId)->first();

        if ($server === null || ! $server->status->isApproved()) {
            return;
        }

        $renderNodeState->handle($server);
    }
}
