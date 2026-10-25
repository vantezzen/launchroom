<?php

declare(strict_types=1);

namespace App\Jobs\Security;

use App\Actions\Security\BuildInvestigationReport;
use App\Models\Security\SecurityInvestigation;
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
 * Writes an investigation's report once its inspections are done (docs/22 §7.5).
 */
#[Timeout(120)]
#[Tries(3)]
#[Backoff(10)]
#[UniqueFor(300)]
final class BuildInvestigationReportJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $investigationId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->investigationId;
    }

    public function handle(BuildInvestigationReport $build): void
    {
        $investigation = SecurityInvestigation::query()->whereKey($this->investigationId)->first();

        if ($investigation instanceof SecurityInvestigation) {
            $build->handle($investigation);
        }
    }
}
