<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Enums\Security\InvestigationStatus;
use App\Jobs\Security\BuildInvestigationReportJob;
use App\Models\Security\SecurityInvestigation;
use App\Support\Metrics\Cast;

/**
 * Investigations whose inspections never came back (a server that went
 * away mid-task) get their report after 10 minutes, with the missing
 * replicas as unchecked (docs/22 §7.5: partial).
 */
final readonly class SweepInvestigations
{
    public function handle(): int
    {
        $stale = SecurityInvestigation::query()
            ->where('status', InvestigationStatus::Running->value)
            ->where('started_at', '<', now()->subMinutes(10))
            ->pluck('id');

        foreach ($stale as $id) {
            dispatch(new BuildInvestigationReportJob(Cast::id($id)));
        }

        return $stale->count();
    }
}
