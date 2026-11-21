<?php

declare(strict_types=1);

namespace App\Actions\AppUpdates;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\AppUpdates\AutoUpdateData;
use App\Data\AppUpdates\UpdateWindowData;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AppUpdates\AutoUpdateMode;
use App\Enums\AuditAction;
use App\Enums\ServiceKind;
use App\Events\CanvasChanged;
use App\Exceptions\DomainRuleViolation;
use App\Models\ServiceInstance;
use App\Services\AppUpdates\UpdatePlan;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Source → Update automatically (docs/04 §14.3): saved at once,
 * not staged (it changes no running version). A service with data needs
 * backup storage first, because automatic updates always back up first.
 */
final readonly class SaveAutoUpdate
{
    public function __construct(
        private UpdatePlan $plan,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, AutoUpdateData $data, AuditActor $actor): ServiceInstance
    {
        return DB::transaction(function () use ($instance, $data, $actor): ServiceInstance {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'environment', 'volumes'])->lockForUpdate()->firstOrFail();

            if ($instance->service->kind !== ServiceKind::Image) {
                throw DomainRuleViolation::on('mode', 'Only image services update to newer releases.');
            }

            if ($data->mode !== AutoUpdateMode::Off && $this->plan->backupSubjects($instance) !== [] && ! $this->plan->hasBackupStorage($instance)) {
                throw DomainRuleViolation::on('mode', 'Set up backup storage first: automatic updates back up the data before they run.');
            }

            $window = $data->window ?? $instance->auto_update_window ?? UpdateWindowData::default();
            $before = $instance->auto_update;
            $instance->forceFill(['auto_update' => $data->mode, 'auto_update_window' => $window])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::AutoUpdateChanged,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: ['environment' => $instance->environment->public_id, 'from' => $before->value, 'to' => $data->mode->value, 'days' => array_map(strval(...), $window->days), 'start' => $window->start, 'hours' => $window->hours, 'timezone' => $window->timezone],
            ));
            CanvasChanged::forInstance($instance);

            return $instance;
        });
    }
}
