<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Jobs\Audit\DeliverAuditSinkJob;
use App\Models\AuditSink;

/**
 * "Retry now" (docs/09 R34): deliver without waiting for the backoff.
 */
final readonly class RetryAuditSink
{
    public function handle(AuditSink $sink): void
    {
        dispatch(new DeliverAuditSinkJob($sink->id, true));
    }
}
