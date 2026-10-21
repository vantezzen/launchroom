<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The desired state of these servers may have changed (intent, routes,
 * deployments). A listener queues one debounced re-render per server.
 */
final readonly class NodeStateInvalidated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $serverIds
     */
    public function __construct(
        public array $serverIds,
    ) {}
}
