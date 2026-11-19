<?php

declare(strict_types=1);

namespace App\Actions\Updates;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Backups\FailAbandonedControlPlaneBackups;
use App\Data\Audit\AuditActor;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\DaemonTaskStatus;
use App\Enums\ServerStatus;
use App\Enums\Updates\ControlPlaneUpdateStatus;
use App\Enums\Updates\FleetUpdateStatus;
use App\Enums\Updates\FleetUpdateStepStatus;
use App\Enums\Updates\UpdateScope;
use App\Jobs\Updates\RunControlPlaneUpdateJob;
use App\Models\Backups\BackupRun;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\Updates\ControlPlaneUpdate;
use App\Models\Updates\FleetUpdate;
use App\Models\Updates\FleetUpdateStep;
use App\Services\Updates\SelfUpdateTasks;
use App\Support\Refusal;
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
 *   Nothing waits silently (docs/11 §4.3): control-plane backups whose
 *   worker died are given up first ({@see FailAbandonedControlPlaneBackups});
 *   an update whose job may have died (no progress for
 *   `redispatch_seconds`: queued, between steps, after a finished or given
 *   up backup, before its task exists) is dispatched again, and its job
 *   continues where it stopped; a self_update task that launchroomd
 *   doesn't take within `task_start_seconds` is withdrawn and the update
 *   cancelled (nothing was changed yet).
 * - Then servers: a succeeded control-plane update with `update_servers`
 *   starts the rolling server update once the new dashboard runs (its
 *   version is the update's target), at most once (`fleet_update_id`).
 * - Servers: strictly one step at a time. A finished step decides; the
 *   first failure skips the remaining steps and fails the run; otherwise
 *   the next server gets its task. A task the server doesn't take within
 *   `task_start_seconds` (offline) fails its step. No steps left →
 *   succeeded, or cancelled when the owner stopped it ({@see CancelFleetUpdate}).
 */
final readonly class AdvanceUpdates
{
    public const string NOT_STARTED = 'The main server didn’t start the update within 30 minutes (it may be offline), so it was cancelled. Nothing was changed.';

    public function __construct(
        private SelfUpdateTasks $tasks,
        private RecordAuditEvent $audit,
        private StartFleetUpdate $startFleet,
        private FailAbandonedControlPlaneBackups $abandonedBackups,
        private CancelControlPlaneUpdate $cancel,
    ) {}

    public function handle(): void
    {
        $this->abandonedBackups->handle();
        $this->controlPlane();
        $this->serversAfterControlPlane();

        $running = FleetUpdate::query()->where('status', FleetUpdateStatus::Running->value)->get(['id']);

        foreach ($running as $update) {
            $this->fleet($update->id);
        }
    }

    private function controlPlane(): void
    {
        $redispatch = false;
        $withdraw = null;

        $finished = DB::transaction(function () use (&$redispatch, &$withdraw): ?ControlPlaneUpdate {
            $update = ControlPlaneUpdate::query()
                ->whereIn('status', ControlPlaneUpdateStatus::nonTerminal())
                ->lockForUpdate()
                ->first();

            if (! $update instanceof ControlPlaneUpdate) {
                return null;
            }

            $quiet = now()->subSeconds(config()->integer('launchroom.updates.redispatch_seconds'));

            if ($update->status === ControlPlaneUpdateStatus::Pending) {
                // The job was lost (worker restart): start it again.
                $redispatch = $update->created_at?->lt($quiet) ?? false;

                return null;
            }

            if ($update->status === ControlPlaneUpdateStatus::BackingUp) {
                $backup = $update->backup_run_id === null ? null : BackupRun::query()->find($update->backup_run_id);

                // The backup finished (or was given up): continue. Otherwise
                // the job died before the backup started, or the backup is
                // queued and nobody took it: the job takes it over.
                if ($backup instanceof BackupRun && $backup->status->isTerminal()) {
                    $redispatch = true;
                } elseif (! $backup instanceof BackupRun || $backup->status === BackupRunStatus::Pending) {
                    $redispatch = ($update->updated_at?->lt($quiet) ?? false) && ($backup?->created_at?->lt($quiet) ?? true);
                }

                // A running backup beats until it ends; one that stops is
                // given up above, and this continues next minute.
                return null;
            }

            $task = $update->daemon_task_id === null ? null : DaemonTask::query()->find($update->daemon_task_id);

            // The job died before it created the task: nothing changed yet
            // (this dashboard still runs the old version).
            if ($task === null && config()->string('launchroom.version') === $update->from_version) {
                if ($update->updated_at?->lt(now()->subSeconds(config()->integer('launchroom.updates.task_start_seconds'))) ?? false) {
                    $update->transitionTo(ControlPlaneUpdateStatus::Failed, 'The update could not be handed to the main server, so nothing was changed. Check Settings → System, then try again.');

                    return $update;
                }

                $redispatch = $update->updated_at?->lt($quiet) ?? false;

                return null;
            }

            if ($task instanceof DaemonTask && $task->status === DaemonTaskStatus::Pending
                && ($task->updated_at?->lt(now()->subSeconds(config()->integer('launchroom.updates.task_start_seconds'))) ?? false)) {
                $withdraw = $update;

                return null;
            }

            if ($task instanceof DaemonTask && ! $task->status->isTerminal()) {
                return null;
            }

            if ($task === null && ($update->updated_at?->gt(now()->subMinutes(10)) ?? true)) {
                return null; // The new version runs; its task record may still be on its way.
            }

            [$status, $error] = $this->outcome($task);

            if ($status === FleetUpdateStepStatus::RolledBack && is_string($task?->result?->selfUpdate?->reason)) {
                // The page names the version it went back to; the reason is the rest.
                $error = $task->result->selfUpdate->reason;
            }

            $update->transitionTo(match ($status) {
                FleetUpdateStepStatus::Succeeded => ControlPlaneUpdateStatus::Succeeded,
                FleetUpdateStepStatus::RolledBack => ControlPlaneUpdateStatus::RolledBack,
                default => ControlPlaneUpdateStatus::Failed,
            }, $error);

            return $update;
        });

        if ($finished instanceof ControlPlaneUpdate) {
            $this->audit->handle($finished->finishedAuditEntry());
        }

        if ($withdraw instanceof ControlPlaneUpdate) {
            $this->cancel->handle($withdraw, AuditActor::system(), self::NOT_STARTED);
        }

        if ($redispatch && $finished === null) {
            $update = ControlPlaneUpdate::query()->whereIn('status', ControlPlaneUpdateStatus::nonTerminal())->first();

            if ($update instanceof ControlPlaneUpdate) {
                dispatch(new RunControlPlaneUpdateJob($update->id));
            }
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

        $fleet = $this->startFleet->handle($update->actor, AuditActor::system());

        if ($fleet instanceof Refusal && $fleet->isConflict()) {
            return; // Something else is running: next minute.
        }

        if ($fleet instanceof Refusal) {
            // Nothing to roll out (every server is current) or not possible: don't retry.
            Log::info('No server update after the control-plane update.', ['update' => $update->public_id, 'reason' => $fleet->message]);
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
                $notStarted = $task instanceof DaemonTask && $task->status === DaemonTaskStatus::Pending
                    && ($task->updated_at?->lt(now()->subSeconds(config()->integer('launchroom.updates.task_start_seconds'))) ?? false);

                if ($task instanceof DaemonTask && ! $task->status->isTerminal() && ! $notStarted) {
                    return null;
                }

                if ($notStarted) {
                    // The server never took it (offline): withdrawn, nothing changed there.
                    $task->forceFill(['error' => 'The server did not start the update.']);
                    $task->transitionTo(DaemonTaskStatus::Cancelled);
                }

                [$status, $error] = $notStarted
                    ? [FleetUpdateStepStatus::Failed, 'The server didn’t start the update within 30 minutes. It may be offline; nothing was changed on it.']
                    : $this->outcome($task);
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

            $cancelled = $steps->contains(static fn (FleetUpdateStep $step): bool => $step->status === FleetUpdateStepStatus::Skipped && $step->error === CancelFleetUpdate::SKIPPED);
            $update->transitionTo($cancelled ? FleetUpdateStatus::Cancelled : FleetUpdateStatus::Succeeded, $cancelled ? 'Cancelled.' : null);

            return $update;
        });

        if ($finished instanceof FleetUpdate) {
            $this->audit->handle($finished->finishedAuditEntry());
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

        $rolledBack = $task->result?->selfUpdate?->rolledBack === true;
        $error = $task->error ?? ($task->status === DaemonTaskStatus::Cancelled ? 'The update was cancelled.' : 'The update failed.');

        return [$rolledBack ? FleetUpdateStepStatus::RolledBack : FleetUpdateStepStatus::Failed, $error];
    }
}
