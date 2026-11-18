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
use Illuminate\Support\Facades\DB;

/**
 * Adds firewall exceptions to a service instance in one step (docs/22
 * §6.4): "Allow requests like this" adds one per rule that scored on a
 * blocked request, all or none. Typed fields only (a detection rule, a path
 * prefix, a request part); exceptions that already exist are skipped, so a
 * second click adds only what the first one missed; at most 50 per
 * instance. Each one is audited (`waf.exclusion_added`); the team's edges
 * re-render once. launchroomd renders the directives itself.
 */
final readonly class AddWafExclusions
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @param  list<AddWafExclusionData>  $items
     * @return list<WafExclusion> the exceptions added (none when all existed)
     */
    public function handle(ServiceInstance $instance, array $items, User $user, AuditActor $actor): array
    {
        return DB::transaction(function () use ($instance, $items, $user, $actor): array {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'environment'])->lockForUpdate()->firstOrFail();
            $existing = WafExclusion::query()->where('service_instance_id', $instance->id)->get(['rule_id', 'path_prefix', 'target']);
            $seen = [];

            foreach ($existing as $exclusion) {
                $seen[$this->key($exclusion->rule_id, $exclusion->path_prefix, $exclusion->target)] = true;
            }

            $new = [];

            foreach ($items as $item) {
                $path = $item->path === '' ? null : $item->path;
                $target = $item->target === '' ? null : $item->target;
                $key = $this->key($item->rule, $path, $target);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $new[] = [$item, $path, $target];
            }

            if ($existing->count() + count($new) > WafExclusion::MAX_PER_INSTANCE) {
                throw DomainRuleViolation::on('rule', 'A service can have at most 50 firewall exceptions. Remove one first.');
            }

            $added = [];

            foreach ($new as [$item, $path, $target]) {
                $note = $item->note === null ? null : trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $item->note)));
                $exclusion = WafExclusion::query()->create([
                    'team_id' => $instance->team_id,
                    'service_instance_id' => $instance->id,
                    'rule_id' => $item->rule,
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
                    meta: array_filter(['environment' => $instance->environment->public_id, 'exclusion' => $exclusion->public_id, 'rule' => (string) $item->rule, 'path' => $path, 'target' => $target]),
                ));

                $added[] = $exclusion;
            }

            if ($added !== []) {
                NodeStateInvalidated::edges($instance->team_id);
            }

            return $added;
        });
    }

    private function key(int $rule, ?string $path, ?string $target): string
    {
        return $rule."\n".$path."\n".$target;
    }
}
