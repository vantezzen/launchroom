<?php

declare(strict_types=1);

namespace App\Jobs\Audit;

use App\Services\Audit\AuditForwarder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Forwards new audit entries to one sink (docs/09 R34), dispatched after
 * every audit entry commits; one queued job per sink at a time. Failures are
 * the sink's own backoff (AuditForwarder), not queue retries, and
 * `launchroom:audit:deliver` picks up whatever this missed.
 */
#[Timeout(90)]
#[Tries(1)]
#[UniqueFor(120)]
final class DeliverAuditSinkJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $sinkId,
        public readonly bool $force = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->sinkId.($this->force ? ':force' : '');
    }

    public function handle(AuditForwarder $forwarder): void
    {
        $forwarder->deliver($this->sinkId, $this->force);
    }
}
