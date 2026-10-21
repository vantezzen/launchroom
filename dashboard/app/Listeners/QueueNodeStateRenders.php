<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\NodeStateInvalidated;
use App\Jobs\RenderServerNodeState;

/**
 * One queued render per invalidated server; RenderServerNodeState is unique
 * per server until it starts, so bursts of invalidations collapse.
 */
final class QueueNodeStateRenders
{
    public function handle(NodeStateInvalidated $event): void
    {
        foreach (array_unique($event->serverIds) as $serverId) {
            dispatch(new RenderServerNodeState($serverId));
        }
    }
}
