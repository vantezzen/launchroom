<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Security\SecurityFindingStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Security\EvaluateSecurityFindingsJob;
use App\Models\Security\SecurityFinding;
use Illuminate\Support\Facades\DB;

/**
 * Reopens a dismissed finding (docs/22 §5.5); audited. The team is
 * re-evaluated so its level and alert are current.
 */
final readonly class ReopenFinding
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(SecurityFinding $finding, AuditActor $actor): SecurityFinding
    {
        return DB::transaction(function () use ($finding, $actor): SecurityFinding {
            $finding = SecurityFinding::query()->whereKey($finding->id)->with('serviceInstance.service')->lockForUpdate()->firstOrFail();

            if ($finding->status !== SecurityFindingStatus::Dismissed) {
                throw DomainRuleViolation::conflict('Only dismissed findings can be reopened.');
            }

            $finding->forceFill(['notified_at' => null, 'level_changed_at' => now()]);
            $finding->transitionTo(SecurityFindingStatus::Open);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::SecurityFindingReopened,
                actor: $actor,
                teamId: $finding->team_id,
                subjectType: 'service',
                subjectId: $finding->serviceInstance->service->public_id,
                meta: ['finding' => $finding->public_id, 'advisory' => $finding->group_key],
            ));

            dispatch(new EvaluateSecurityFindingsJob($finding->team_id))->afterCommit();

            return $finding;
        });
    }
}
