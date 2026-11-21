<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Security\DismissFindingData;
use App\Enums\AuditAction;
use App\Enums\Security\SecurityFindingStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Security\SecurityFinding;
use App\Models\User;
use App\Support\Security\DismissNote;
use Illuminate\Support\Facades\DB;

/**
 * "Not affected" (docs/22 §5.5): dismisses one finding of one service
 * instance with a reason and an optional note; audited. It reopens by
 * itself when it gets more urgent. The package's alert resolves when no
 * open "Action needed" finding of it is left.
 */
final readonly class DismissFinding
{
    public function __construct(
        private RecordAuditEvent $audit,
        private ResolveVulnerabilityAlerts $settle,
    ) {}

    public function handle(SecurityFinding $finding, DismissFindingData $data, User $user, AuditActor $actor): SecurityFinding
    {
        $finding = DB::transaction(function () use ($finding, $data, $user, $actor): SecurityFinding {
            $finding = SecurityFinding::query()->whereKey($finding->id)->with('serviceInstance.service')->lockForUpdate()->firstOrFail();

            if ($finding->status !== SecurityFindingStatus::Open) {
                throw DomainRuleViolation::conflict('Only open findings can be dismissed.');
            }

            $finding->forceFill([
                'dismissed_at' => now(),
                'dismissed_by' => $user->id,
                'dismiss_reason' => $data->reason,
                'dismiss_note' => DismissNote::clean($data->note),
            ]);
            $finding->transitionTo(SecurityFindingStatus::Dismissed);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::SecurityFindingDismissed,
                actor: $actor,
                teamId: $finding->team_id,
                subjectType: 'service',
                subjectId: $finding->serviceInstance->service->public_id,
                meta: ['finding' => $finding->public_id, 'advisory' => $finding->group_key, 'package' => "{$finding->name} {$finding->version}", 'reason' => $data->reason->value],
            ));

            return $finding;
        });

        $this->settle->handle($finding->team_id, [$finding]);

        return $finding;
    }
}
