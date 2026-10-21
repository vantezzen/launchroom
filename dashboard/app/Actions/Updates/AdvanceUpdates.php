<?php

declare(strict_types=1);

namespace App\Actions\Updates;

use App\Data\Audit\AuditActor;
use App\Enums\DaemonTaskStatus;
use App\Enums\ServerStatus;
use App\Enums\Updates\ControlPlaneUpdateStatus;
use App\Enums\Updates\FleetUpdateStatus;
use App\Enums\Updates\FleetUpdateStepStatus;
use App\Enums\Updates\UpdateScope;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Updates\RunControlPlaneUpdateJob;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\Updates\ControlPlaneUpdate;
use App\Models\Updates\FleetUpdate;
use App\Models\Updates\FleetUpdateStep;
use App\Services\Updates\SelfUpdateTasks;
use App\Services\Updates\UpdateAudit;
use App\Support\Semver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moves updates forward from their self_update tasks (docs/11 §4). Runs when
 * a self_update task finishes and every minute from the scheduler: the
 * control plane restarts during its own update, so events can be lost.
 * Idempotent.
 *
 * - Control plane: task succeeded → succeeded; failed with
 *   `result.selfUpdate.rolledBack` → rolled_back; any other end → failed.
 *   A pending update whose job got lost is dispatched again; a backup that
 *   never finished fails the update (nothing was changed yet).
 * - Then servers: a succeeded control-plane update with `update_servers`
 *   starts the rolling server update once the new dashboard runs (its
 *   version is the update's target), at most once (`fleet_update_id`).
 * - Servers: strictly one step at a time. A finished step decides; the
 *   first failure skips the remaining steps and fails the run; otherwise
 *   the next server gets its task. No steps left → succeeded.
 */
final readonly class AdvanceUpdates
{
    public function __construct(
        private SelfUpdateTasks $tasks,
        private UpdateAudit $audit,
        private StartFleetUpdate $startFleet,
    ) {}

    public function handle(): void
    {
        $this->controlPlane();
        $this->serversAfterControlPlane();

        $running = FleetUpdate::query()->where('status', FleetUpdateStatus::Running->value)->get(['id']);

        foreach ($running as $update) {
            $this->fleet($update->id);
        }
    }

    private function controlPlane(): void
    {
        $finished = DB::transaction(function (): ?ControlPlaneUpdate {
            $update = ControlPlaneUpdate::query()
                ->whereIn('status', [ControlPlaneUpdateStatus::Pending->value, ControlPlaneUpdateStatus::BackingUp->value, ControlPlaneUpdateStatus::Updating->value])
                ->lockForUpdate()
                ->first();

            if (! $update instanceof ControlPlaneUpdate) {
                return null;
            }

            if ($update->status === ControlPlaneUpdateStatus::Pending) {
                // The job was lost (worker restart): start it again.
                if ($update->created_at?->lt(now()->subMinutes(5)) ?? false) {
                    dispatch(new RunControlPlaneUpdateJob($update->id));
                }

                return null;
            }

            if ($update->status === ControlPlaneUpdateStatus::BackingUp) {
                // The worker running the backup died; nothing was changed yet.
                if ($update->updated_at?->lt(now()->subHours(3)) ?? false) {
                    $update->transitionTo(ControlPlaneUpdateStatus::Failed, 'The pre-upgrade backup did not finish, so nothing was changed.');

                    return $update;
                }

                return null;
            }

            $task = $update->daemon_task_id === null ? null : DaemonTask::query()->find($update->daemon_task_id);

            if ($task instanceof DaemonTask && ! $task->status->isTerminal()) {
                return null;
            }

            if ($task === null && ($update->updated_at?->gt(now()->subMinutes(10)) ?? true)) {
                return null; // The task is being created.
            }

            [$status, $error] = $this->outcome($task);
            $update->transitionTo(match ($status) {
                FleetUpdateStepStatus::Succeeded => ControlPlaneUpdateStatus::Succeeded,
                FleetUpdateStepStatus::RolledBack => ControlPlaneUpdateStatus::RolledBack,
                default => ControlPlaneUpdateStatus::Failed,
            }, $error);

            return $update;
        });

        if ($finished instanceof ControlPlaneUpdate) {
            $this->audit->controlPlaneFinished($finished);
        }
    }

    /**
     * "Update now" updates launchroom and then every server: the rollout to
     * the new version is started by the new dashboard (only it knows the
     * new launchroomd image), within a day of the control-plane update.
     */
    private function serversAfterControlPlane(): void
    {
        $update = ControlPlaneUpdate::query()
            ->where('status', ControlPlaneUpdateStatus::Succeeded->value)
            ->where('update_servers', true)
            ->whereNull('fleet_update_id')
            ->where('finished_at', '>', now()->subDay())
            ->latest('id')
            ->first();

        if (! $update instanceof ControlPlaneUpdate || Semver::compare($update->to_version, config()->string('launchroom.version')) !== 0) {
            return;
        }

        try {
            $fleet = $this->startFleet->handle($update->actor, AuditActor::system());
        } catch (DomainRuleViolation $e) {
            if ($e->status === 409) {
                return; // Something else is running: next minute.
            }

            // Nothing to roll out (every server is current) or not possible: don't retry.
            Log::info('No server update after the control-plane update.', ['update' => $update->public_id, 'reason' => $e->getMessage()]);
            $update->forceFill(['update_servers' => false])->save();

            return;
        }

        $update->forceFill(['fleet_update_id' => $fleet->id])->save();
    }

    private function fleet(int $id): void
    {
        $finished = DB::transaction(function () use ($id): ?FleetUpdate {
            $update = FleetUpdate::query()->whereKey($id)->lockForUpdate()->first();

            if (! $update instanceof FleetUpdate || $update->status !== FleetUpdateStatus::Running) {
                return null;
            }

            $steps = $update->steps()->get();
            $current = $steps->first(static fn (FleetUpdateStep $step): bool => $step->status === FleetUpdateStepStatus::Updating);

            if ($current instanceof FleetUpdateStep) {
                $task = $current->daemon_task_id === null ? null : DaemonTask::query()->find($current->daemon_task_id);

                if ($task instanceof DaemonTask && ! $task->status->isTerminal()) {
                    return null;
                }

                [$status, $error] = $this->outcome($task);
                $current->transitionTo($status, $error);

                if ($status !== FleetUpdateStepStatus::Succeeded) {
                    foreach ($steps as $step) {
                        if ($step->status === FleetUpdateStepStatus::Pending) {
                            $step->transitionTo(FleetUpdateStepStatus::Skipped, 'Not updated: an earlier server failed.');
                        }
                    }

                    $update->transitionTo(FleetUpdateStatus::Failed, "{$current->server_name}: ".($error ?? 'the update failed'));

                    return $update;
                }
            }

            foreach ($steps as $step) {
                if ($step->status !== FleetUpdateStepStatus::Pending) {
                    continue;
                }

                $server = $step->server_id === null ? null : Server::query()->find($step->server_id);

                if (! $server instanceof Server || $server->status === ServerStatus::Revoked) {
                    $step->transitionTo(FleetUpdateStepStatus::Skipped, 'The server was removed.');

                    continue;
                }

                $task = $this->tasks->create($server, $update->to_version, $update->daemon_image, UpdateScope::Daemon);
                $step->forceFill(['daemon_task_id' => $task->id]);
                $step->transitionTo(FleetUpdateStepStatus::Updating);

                return null;
            }

            $update->transitionTo(FleetUpdateStatus::Succeeded);

            return $update;
        });

        if ($finished instanceof FleetUpdate) {
            $this->audit->fleetFinished($finished);
        }
    }

    /**
     * How a finished (or vanished) self_update task ended.
     *
     * @return array{FleetUpdateStepStatus, string|null}
     */
    private function outcome(?DaemonTask $task): array
    {
        if (! $task instanceof DaemonTask) {
            return [FleetUpdateStepStatus::Failed, 'The update task disappeared.'];
        }

        if ($task->status === DaemonTaskStatus::Succeeded) {
            return [FleetUpdateStepStatus::Succeeded, null];
        }

        $selfUpdate = $task->result['selfUpdate'] ?? null;
        $rolledBack = is_array($selfUpdate) && ($selfUpdate['rolledBack'] ?? false) === true;
        $error = $task->error ?? ($task->status === DaemonTaskStatus::Cancelled ? 'The update was cancelled.' : 'The update failed.');

        return [$rolledBack ? FleetUpdateStepStatus::RolledBack : FleetUpdateStepStatus::Failed, $error];
    }
}
