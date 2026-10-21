<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\LogSourceKind;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A daemon shipped log chunks (docs/07). Listeners fan out live-log nudges
 * to the deployments the sources belong to; they never read the lines here.
 */
final readonly class LogChunksStored implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<array{kind: LogSourceKind, id: string}>  $sources
     */
    public function __construct(
        public int $teamId,
        public int $serverId,
        public array $sources,
    ) {}
}
