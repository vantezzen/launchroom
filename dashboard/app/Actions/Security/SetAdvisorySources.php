<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Security\SecuritySourcesData;
use App\Enums\AuditAction;
use App\Enums\Security\AdvisoryFeed;
use App\Jobs\Security\SyncAdvisoriesJob;
use App\Jobs\Security\SyncFeedsJob;
use App\Models\Security\AdvisoryFeedState;
use Illuminate\Support\Facades\DB;

/**
 * Switches the public advisory sources on or off (docs/22 §4.2, §9.8),
 * instance owners only; audited (`instance.security_sources_changed`). A
 * source switched on syncs right away; switched off it is never asked
 * again and its stored data stays until it ages out.
 */
final readonly class SetAdvisorySources
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(SecuritySourcesData $data, AuditActor $actor): void
    {
        DB::transaction(function () use ($data, $actor): void {
            $changed = [];

            foreach ([AdvisoryFeed::Osv->value => $data->osv, AdvisoryFeed::Kev->value => $data->kev, AdvisoryFeed::Eol->value => $data->eol] as $feed => $enabled) {
                $state = AdvisoryFeedState::of(AdvisoryFeed::from($feed));

                if ($state->enabled !== $enabled) {
                    $state->forceFill(['enabled' => $enabled, 'cursor' => null, 'retry_at' => null, 'backoff_seconds' => 0, 'failing_since' => null, 'last_error' => null])->save();
                    $changed[$feed] = $enabled ? 'on' : 'off';
                }
            }

            if ($changed === []) {
                return;
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::SecuritySourcesChanged,
                actor: $actor,
                subjectType: 'instance',
                meta: $changed,
            ));

            if (($changed['osv'] ?? null) === 'on') {
                dispatch(new SyncAdvisoriesJob)->afterCommit();
            }

            if (($changed['kev'] ?? null) === 'on' || ($changed['eol'] ?? null) === 'on') {
                dispatch(new SyncFeedsJob)->afterCommit();
            }
        });
    }
}
