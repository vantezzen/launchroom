<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Security\AddWafExclusionData;
use App\Enums\AuditAction;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\Security\WafExclusion;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\TeamEdges;
use Illuminate\Support\Facades\DB;

/**
 * Adds a firewall exception to a service instance (docs/22 §6.4): typed
 * fields only (a detection rule, a path prefix, a request part), at most
 * 50 per instance, no duplicates; audited (`waf.exclusion_added`); the
 * team's edges re-render. launchroomd renders the directive itself.
 */
final readonly class AddWafExclusion
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, AddWafExclusionData $data, User $user, AuditActor $actor): WafExclusion
    {
        return DB::transaction(function () use ($instance, $data, $user, $actor): WafExclusion {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'environment'])->lockForUpdate()->firstOrFail();
            $path = $data->path === '' ? null : $data->path;
            $target = $data->target === '' ? null : $data->target;
            $existing = WafExclusion::query()->where('service_instance_id', $instance->id);

            if ((clone $existing)->count() >= WafExclusion::MAX_PER_INSTANCE) {
                throw DomainRuleViolation::on('rule', 'A service can have at most 50 firewall exceptions. Remove one first.');
            }

            if ((clone $existing)->where('rule_id', $data->rule)->where('path_prefix', $path)->where('target', $target)->exists()) {
                throw DomainRuleViolation::on('rule', 'This exception already exists.');
            }

            $note = $data->note === null ? null : trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $data->note)));
            $exclusion = WafExclusion::query()->create([
                'team_id' => $instance->team_id,
                'service_instance_id' => $instance->id,
                'rule_id' => $data->rule,
                'path_prefix' => $path,
                'target' => $target,
                'note' => $note === '' ? null : $note,
                'created_by' => $user->id,
            ]);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::WafExclusionAdded,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: array_filter(['environment' => $instance->environment->public_id, 'exclusion' => $exclusion->public_id, 'rule' => (string) $data->rule, 'path' => $path, 'target' => $target]),
            ));

            event(new NodeStateInvalidated(TeamEdges::ids($instance->team_id)));

            return $exclusion;
        });
    }
}
