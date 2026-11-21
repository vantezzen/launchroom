<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Security\DismissFindingsData;
use App\Enums\AuditAction;
use App\Enums\Security\SecurityFindingStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Security\SecurityFinding;
use App\Models\Team;
use App\Models\User;
use App\Support\Security\DismissNote;
use Illuminate\Support\Facades\DB;

/**
 * "Not affected…" for a whole fix group (docs/22 §5.5, D107): dismisses the
 * given open findings of the team in one go, with one reason and note. The
 * dismissal stays per finding, so a service that gets the package later, or
 * a new advisory, opens a new finding and brings the group back. Ids that
 * aren't the team's open findings are ignored; none left is a conflict.
 * One audit event per service. Alerts of packages without an open "Action
 * needed" finding left resolve.
 */
final readonly class DismissFindings
{
    /** Finding ids listed in one service's audit event. */
    private const int AUDITED_IDS = 50;

    public function __construct(
        private RecordAuditEvent $audit,
        private ResolveVulnerabilityAlerts $settle,
    ) {}

    /**
     * @return int dismissed findings
     */
    public function handle(Team $team, DismissFindingsData $data, User $user, AuditActor $actor): int
    {
        $findings = DB::transaction(function () use ($team, $data, $user, $actor): array {
            $findings = SecurityFinding::query()
                ->where('team_id', $team->id)
                ->whereIn('public_id', $data->findings)
                ->where('status', SecurityFindingStatus::Open->value)
                ->with('serviceInstance.service')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($findings->isEmpty()) {
                throw DomainRuleViolation::conflict('These findings are no longer open.');
            }

            $note = DismissNote::clean($data->note);

            foreach ($findings as $finding) {
                $finding->forceFill([
                    'dismissed_at' => now(),
                    'dismissed_by' => $user->id,
                    'dismiss_reason' => $data->reason,
                    'dismiss_note' => $note,
                ]);
                $finding->transitionTo(SecurityFindingStatus::Dismissed);
            }

            foreach ($findings->groupBy('service_instance_id') as $own) {
                $first = $own->firstOrFail();
                $ids = array_values(array_map(static fn (SecurityFinding $f): string => $f->public_id, $own->take(self::AUDITED_IDS)->all()));

                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::SecurityFindingDismissed,
                    actor: $actor,
                    teamId: $team->id,
                    subjectType: 'service',
                    subjectId: $first->serviceInstance->service->public_id,
                    meta: [
                        'findings' => $ids,
                        'count' => $own->count(),
                        'package' => mb_substr($first->name.' '.$own->pluck('version')->unique()->implode(', '), 0, 200),
                        'reason' => $data->reason->value,
                    ],
                ));
            }

            return $findings->all();
        });

        $this->settle->handle($team->id, $findings);

        return count($findings);
    }
}
