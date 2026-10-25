<?php

declare(strict_types=1);

namespace App\Console\Commands\Security;

use App\Actions\Security\BackfillInventories;
use App\Actions\Security\SweepInvestigations;
use App\Enums\Security\AdvisoryFeed;
use App\Jobs\Security\EvaluateSecurityFindingsJob;
use App\Jobs\Security\SyncAdvisoriesJob;
use App\Jobs\Security\SyncFeedsJob;
use App\Models\Security\AdvisoryFeedState;
use App\Support\Metrics\Cast;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('launchroom:security:backfill')]
#[Description('Inventory live images that have none, retry failed inventories, finish stale investigations and re-evaluate findings (docs/22 §3.1)')]
final class BackfillSecurityCommand extends Command
{
    public function handle(BackfillInventories $backfill, SweepInvestigations $sweep): int
    {
        $result = $backfill->handle();
        $this->info("Requested {$result['requested']} inventory(ies), retried {$result['retried']}.");
        $this->info('Finished '.$sweep->handle().' stale investigation(s).');

        // A paused OSV sync resumes after its back-off; KEV older than a day is fetched (docs/22 §4.3).
        $osv = AdvisoryFeedState::of(AdvisoryFeed::Osv);

        if ($osv->enabled && is_array($osv->cursor) && ($osv->retry_at === null || $osv->retry_at->isPast())) {
            dispatch(new SyncAdvisoriesJob);
        }

        $kev = AdvisoryFeedState::of(AdvisoryFeed::Kev);

        if ($kev->enabled && ($kev->last_success_at === null || $kev->last_success_at->lessThan(now()->subDay()))) {
            dispatch(new SyncFeedsJob(lifecycles: false));
        }

        // Domains and protection change without a new deployment: hourly is soon enough for "Worth knowing".
        foreach (DB::table('image_inventories')->where('status', 'ready')->distinct()->pluck('team_id') as $team) {
            dispatch(new EvaluateSecurityFindingsJob(Cast::id($team)));
        }

        return self::SUCCESS;
    }
}
