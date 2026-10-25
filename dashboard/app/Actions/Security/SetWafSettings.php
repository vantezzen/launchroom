<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Security\SetWafSettingsData;
use App\Enums\AuditAction;
use App\Enums\Security\WafMode;
use App\Events\NodeStateInvalidated;
use App\Models\ServiceInstance;
use App\Queries\TeamEdges;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

/**
 * Changes a service instance's web firewall mode, paranoia or request body limit (docs/22
 * §6.2): applies right away (a route change, not a staged change), audited
 * (`waf.mode_changed`, `waf.paranoia_changed`, `waf.inspect_body_changed`); the team's edges re-render.
 * SetWafMode in the design doc.
 */
final readonly class SetWafSettings
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, SetWafSettingsData $data, AuditActor $actor): ServiceInstance
    {
        return DB::transaction(function () use ($instance, $data, $actor): ServiceInstance {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'environment'])->lockForUpdate()->firstOrFail();
            $changed = false;

            if ($data->mode instanceof WafMode && $data->mode !== $instance->waf_mode) {
                $from = $instance->waf_mode;
                $instance->forceFill(['waf_mode' => $data->mode, 'waf_mode_changed_at' => now()])->save();
                $this->record(AuditAction::WafModeChanged, $instance, $actor, ['from' => $from->value, 'to' => $data->mode->value]);
                $changed = true;
            }

            if (! $data->paranoia instanceof Optional && $data->paranoia !== $instance->waf_paranoia) {
                $from = $instance->waf_paranoia;
                $instance->forceFill(['waf_paranoia' => $data->paranoia])->save();
                $this->record(AuditAction::WafParanoiaChanged, $instance, $actor, ['from' => (string) $from, 'to' => (string) $data->paranoia]);
                $changed = true;
            }

            if (! $data->inspectBodyKiB instanceof Optional && $data->inspectBodyKiB !== $instance->waf_inspect_body_kib) {
                $from = $instance->waf_inspect_body_kib;
                $instance->forceFill(['waf_inspect_body_kib' => $data->inspectBodyKiB])->save();
                $this->record(AuditAction::WafInspectBodyChanged, $instance, $actor, ['from' => (string) $from, 'to' => (string) $data->inspectBodyKiB]);
                $changed = true;
            }

            if ($changed) {
                event(new NodeStateInvalidated(TeamEdges::ids($instance->team_id)));
            }

            return $instance;
        });
    }

    /**
     * @param  array<string, string>  $meta
     */
    private function record(AuditAction $action, ServiceInstance $instance, AuditActor $actor, array $meta): void
    {
        $this->audit->handle(new AuditEntryData(
            action: $action,
            actor: $actor,
            teamId: $instance->team_id,
            subjectType: 'service',
            subjectId: $instance->service->public_id,
            meta: ['environment' => $instance->environment->public_id, ...$meta],
        ));
    }
}
