<?php

declare(strict_types=1);

namespace App\Actions\Daemons;

use App\Data\Protocol\V1\TaskCompletion;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

/**
 * Records a task outcome. Idempotent by (task id, attempt) (docs/03 §7): a
 * repeated completion of the attempt that already completed is a no-op.
 * The terminal transition emits DaemonTaskFinished, which advances the
 * deployment the task belongs to and records plan previews.
 *
 * `released` (launchroomd shut down before the task finished, e.g. a restart
 * or upgrade) is not an outcome of the task: it goes back to the queue for
 * another attempt right away, like an expired lease, without using up an
 * attempt. Only its deadline bounds it (a passed deadline fails it).
 */
final class CompleteTask
{
    public function handle(Server $server, DaemonTask $task, TaskCompletion $completion): void
    {
        DB::transaction(function () use ($server, $task, $completion): void {
            $task = DaemonTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($task->server_id !== $server->id) {
                throw DomainRuleViolation::conflict('This server does not hold this task.');
            }

            if ($task->completed_attempt === $completion->attempt && $task->status->isTerminal()) {
                return;
            }

            if ($task->status !== DaemonTaskStatus::Leased || $task->attempt !== $completion->attempt) {
                throw DomainRuleViolation::conflict('This server does not hold a lease for this task attempt.');
            }

            if ($completion->outcome === 'released') {
                $this->release($task, $completion);

                return;
            }

            $result = array_filter([
                'build' => $completion->build instanceof Optional ? null : $completion->build?->toArray(),
                'runOnce' => $completion->runOnce instanceof Optional ? null : $completion->runOnce?->toArray(),
                'plan' => $completion->plan instanceof Optional ? null : $completion->plan?->toArray(),
                'failure' => $completion->failure instanceof Optional ? null : $completion->failure?->toArray(),
                // Backup task results (docs/14 §7).
                'backup' => $completion->backup instanceof Optional ? null : $completion->backup?->toArray(),
                'restore' => $completion->restore instanceof Optional ? null : $completion->restore?->toArray(),
                'snapshots' => $completion->snapshots instanceof Optional ? null : $completion->snapshots?->toArray(),
                'maintenance' => $completion->maintenance instanceof Optional ? null : $completion->maintenance?->toArray(),
                // verify_restore (docs/14 §10.4).
                'verify' => $completion->verify instanceof Optional ? null : $completion->verify?->toArray(),
                // self_update (docs/11 §4), on success and failure.
                'selfUpdate' => $completion->selfUpdate instanceof Optional ? null : $completion->selfUpdate?->toArray(),
                // Interactive sessions (docs/03 §11.1).
                'session' => $completion->session instanceof Optional ? null : $completion->session?->toArray(),
                // Repository inspection for "Generate a Dockerfile" (docs/20 §6).
                'inspection' => $completion->inspection instanceof Optional ? null : $completion->inspection?->toArray(),
                // Data import and export (D55, docs/14 §12).
                'dataImport' => $completion->dataImport instanceof Optional ? null : $completion->dataImport?->toArray(),
                'dataExport' => $completion->dataExport instanceof Optional ? null : $completion->dataExport?->toArray(),
            ], static fn (?array $value): bool => $value !== null);

            $task->forceFill([
                'completed_attempt' => $completion->attempt,
                'result' => $result === [] ? null : $result,
                'error' => $completion->error instanceof Optional ? null : $completion->error,
                'lease_expires_at' => null,
            ]);

            $task->transitionTo(match ($completion->outcome) {
                'succeeded' => DaemonTaskStatus::Succeeded,
                'cancelled' => DaemonTaskStatus::Cancelled,
                default => DaemonTaskStatus::Failed,
            });
        });
    }

    private function release(DaemonTask $task, TaskCompletion $completion): void
    {
        $error = $completion->error instanceof Optional ? 'launchroomd stopped before the task finished.' : $completion->error;

        // A session can't move to another process: its shell and key are gone (docs/03 §11.1).
        if ($task->type === DaemonTaskType::Session || ($task->deadline_at?->isPast() ?? false)) {
            $task->forceFill(['completed_attempt' => $completion->attempt, 'error' => $error, 'lease_expires_at' => null]);
            $task->transitionTo(DaemonTaskStatus::Failed);

            return;
        }

        $task->forceFill([
            'lease_expires_at' => null,
            'server_id' => $task->pinned_to_server ? $task->server_id : null,
            // A release is not a failed attempt: keep the attempt budget.
            'max_attempts' => max($task->max_attempts, $task->attempt + 1),
            'error' => null,
        ]);
        $task->transitionTo(DaemonTaskStatus::Pending);
    }
}
