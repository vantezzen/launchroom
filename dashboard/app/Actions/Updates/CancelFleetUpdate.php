<?php

declare(strict_types=1);

namespace App\Actions\Updates;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DaemonTaskStatus;
use App\Enums\Updates\FleetUpdateStatus;
use App\Enums\Updates\FleetUpdateStepStatus;
use App\Models\DaemonTask;
use App\Models\Updates\FleetUpdate;
use App\Models\Updates\FleetUpdateStep;
use App\Support\Refusal;
use Illuminate\Support\Facades\DB;

/**
 * "Stop updating servers" (docs/11 §4.3): servers that haven't started are
 * skipped, and a server whose task launchroomd hasn't taken yet (offline)
 * too. A server that is installing finishes (it goes back to its old
 * version by itself when the new one doesn't start); the rollout then ends
 * `cancelled` ({@see AdvanceUpdates}).
 */
final readonly class CancelFleetUpdate
{
    public const string SKIPPED = 'Not updated: the update was cancelled.';

    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(FleetUpdate $update, AuditActor $actor): FleetUpdate|Refusal
    {
        $result = DB::transaction(static function () use ($update): FleetUpdate|Refusal {
            $locked = FleetUpdate::query()->whereKey($update->id)->lockForUpdate()->first();

            if (! $locked instanceof FleetUpdate || $locked->status !== FleetUpdateStatus::Running) {
                return Refusal::conflict('This update has already finished.');
            }

            $installing = false;

            foreach ($locked->steps()->lockForUpdate()->get() as $step) {
                if ($step->status === FleetUpdateStepStatus::Pending) {
                    $step->transitionTo(FleetUpdateStepStatus::Skipped, self::SKIPPED);

                    continue;
                }

                if ($step->status === FleetUpdateStepStatus::Updating) {
                    $installing = ! self::withdraw($step) || $installing;
                }
            }

            if (! $installing) {
                $locked->transitionTo(FleetUpdateStatus::Cancelled, 'Cancelled.');
            }

            return $locked;
        });

        if ($result instanceof FleetUpdate) {
            $this->audit->handle(new AuditEntryData(
                action: AuditAction::FleetUpdateFinished,
                actor: $actor,
                subjectType: 'fleet_update',
                subjectId: $result->public_id,
                meta: ['to' => $result->to_version, 'status' => FleetUpdateStatus::Cancelled->value],
            ));
        }

        return $result;
    }

    /** Withdraws the step's task unless launchroomd took it; true when withdrawn. */
    private static function withdraw(FleetUpdateStep $step): bool
    {
        $task = $step->daemon_task_id === null ? null : DaemonTask::query()->whereKey($step->daemon_task_id)->lockForUpdate()->first();

        if ($task instanceof DaemonTask && $task->status !== DaemonTaskStatus::Pending) {
            return false;
        }

        if ($task instanceof DaemonTask) {
            $task->forceFill(['error' => 'The update was cancelled.']);
            $task->transitionTo(DaemonTaskStatus::Cancelled);
        }

        $step->transitionTo(FleetUpdateStepStatus::Skipped, self::SKIPPED);

        return true;
    }
}
