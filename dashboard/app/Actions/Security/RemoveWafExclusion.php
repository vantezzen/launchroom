<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Events\NodeStateInvalidated;
use App\Models\Security\WafExclusion;
use App\Queries\TeamEdges;
use Illuminate\Support\Facades\DB;

/**
 * Removes a firewall exception (docs/22 §6.4); audited
 * (`waf.exclusion_removed`); the team's edges re-render.
 */
final readonly class RemoveWafExclusion
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(WafExclusion $exclusion, AuditActor $actor): void
    {
        DB::transaction(function () use ($exclusion, $actor): void {
            $exclusion->loadMissing(['serviceInstance.service', 'serviceInstance.environment']);
            $instance = $exclusion->serviceInstance;

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::WafExclusionRemoved,
                actor: $actor,
                teamId: $exclusion->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: array_filter(['environment' => $instance->environment->public_id, 'exclusion' => $exclusion->public_id, 'rule' => (string) $exclusion->rule_id, 'path' => $exclusion->path_prefix, 'target' => $exclusion->target]),
            ));

            $exclusion->delete();
            event(new NodeStateInvalidated(TeamEdges::ids($exclusion->team_id)));
        });
    }
}
