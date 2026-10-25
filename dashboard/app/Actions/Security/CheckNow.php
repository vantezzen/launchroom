<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Enums\Security\AdvisoryFeed;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Security\SyncAdvisoriesJob;
use App\Jobs\Security\SyncFeedsJob;
use App\Models\Security\AdvisoryFeedState;
use Illuminate\Support\Facades\DB;

/**
 * "Check now" on the Security page (docs/22 §4.3): the 6-hourly OSV sync
 * for every package regardless of age, and KEV, at most once per 10
 * minutes per instance.
 */
final readonly class CheckNow
{
    public function handle(): void
    {
        DB::transaction(function (): void {
            $osv = AdvisoryFeedState::query()->whereKey(AdvisoryFeed::Osv->value)->lockForUpdate()->first() ?? AdvisoryFeedState::of(AdvisoryFeed::Osv);
            $wait = config()->integer('launchroom.security.check_now_minutes', 10);

            if ($osv->manual_check_at !== null && $osv->manual_check_at->greaterThan(now()->subMinutes($wait))) {
                throw DomainRuleViolation::conflict('Checked a few minutes ago. You can check again in '.max(1, (int) ceil(now()->diffInMinutes($osv->manual_check_at->addMinutes($wait)))).' min.');
            }

            if (! $osv->enabled && ! AdvisoryFeedState::enabled(AdvisoryFeed::Kev)) {
                throw DomainRuleViolation::conflict('Vulnerability checks are off on this launchroom.');
            }

            $osv->forceFill(['manual_check_at' => now()])->save();
            dispatch(new SyncAdvisoriesJob(null, true))->afterCommit();
            dispatch(new SyncFeedsJob(lifecycles: false))->afterCommit();
        });
    }
}
