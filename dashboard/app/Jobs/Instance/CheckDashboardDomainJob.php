<?php

declare(strict_types=1);

namespace App\Jobs\Instance;

use App\Actions\Instance\DashboardDomain\CheckDashboardDomain;
use App\Models\DashboardDomainChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs the checks of a dashboard domain being set up off the request path
 * (docs/05 §4.1). Idempotent; a closed change is left alone.
 */
#[Timeout(60)]
#[Tries(1)]
#[UniqueFor(60)]
final class CheckDashboardDomainJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $changeId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->changeId;
    }

    public function handle(CheckDashboardDomain $check): void
    {
        $change = DashboardDomainChange::query()->whereKey($this->changeId)->first();

        if ($change instanceof DashboardDomainChange && $change->status->isOpen()) {
            $check->handle($change);
        }
    }
}
