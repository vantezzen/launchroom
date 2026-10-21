<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Jobs\Audit\VerifyAuditLogJob;

/**
 * Queues a full audit log check (docs/09 R34); one at a time.
 */
final readonly class RequestAuditVerification
{
    public function handle(): void
    {
        dispatch(new VerifyAuditLogJob);
    }
}
