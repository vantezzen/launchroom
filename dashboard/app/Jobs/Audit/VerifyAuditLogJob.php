<?php

declare(strict_types=1);

namespace App\Jobs\Audit;

use App\Actions\Audit\VerifyAuditChain;
use App\Services\Audit\AuditVerificationStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;

/**
 * "Verify now" (Settings → Security): a full check from the first entry.
 */
#[Timeout(600)]
#[Tries(1)]
#[UniqueFor(600)]
final class VerifyAuditLogJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(VerifyAuditChain $verify, AuditVerificationStatus $status): void
    {
        $status->record($verify->handle());
    }
}
