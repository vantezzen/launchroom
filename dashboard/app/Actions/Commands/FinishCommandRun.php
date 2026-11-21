<?php

declare(strict_types=1);

namespace App\Actions\Commands;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Commands\CommandRunStatus;
use App\Enums\DaemonTaskStatus;
use App\Models\Commands\CommandRun;
use App\Models\DaemonTask;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

/**
 * Records how a one-off command ended (D105) once its `run_once` task
 * finished: the exit code decides succeeded or failed (any exit is a
 * successful task, docs/03 §7.3); a failed task is a timeout when its
 * deadline passed, else the command couldn't run; a cancelled task is a
 * cancelled run. Idempotent: a run that already ended is left alone.
 */
final readonly class FinishCommandRun
{
    /** launchroomd's error when the task's context deadline (spec.timeout) passed. */
    private const string DEADLINE = 'deadline exceeded';

    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(DaemonTask $task): void
    {
        DB::transaction(function () use ($task): void {
            $run = CommandRun::query()->where('daemon_task_id', $task->id)->lockForUpdate()->first();

            if (! $run instanceof CommandRun || $run->status->isTerminal()) {
                return;
            }

            $outcome = $task->result?->runOnce;
            [$status, $note] = match ($task->status) {
                DaemonTaskStatus::Succeeded => [$outcome?->exitCode === 0 ? CommandRunStatus::Succeeded : CommandRunStatus::Failed, null],
                DaemonTaskStatus::Cancelled => [CommandRunStatus::Cancelled, null],
                DaemonTaskStatus::Failed => $task->error === self::DEADLINE
                    ? [CommandRunStatus::TimedOut, null]
                    : [CommandRunStatus::Error, $task->error ?? 'The server couldn’t run the command.'],
                DaemonTaskStatus::Pending, DaemonTaskStatus::Leased => [null, null],
            };

            if ($status === null) {
                return;
            }

            $run->exit_code = $outcome?->exitCode;
            $run->duration_ms = $outcome->durationMs ?? ($run->started_at === null ? null : (int) $run->started_at->diffInMilliseconds(now()));
            $run->output_truncated = $outcome !== null && ! $outcome->outputTruncated instanceof Optional && $outcome->outputTruncated;
            $run->transitionTo($status, $note);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::CommandFinished,
                actor: AuditActor::system(),
                teamId: $run->team_id,
                subjectType: 'command_run',
                subjectId: $run->public_id,
                meta: array_filter([
                    'status' => $status->value,
                    'exitCode' => $run->exit_code,
                    'durationMs' => $run->duration_ms,
                ], static fn (mixed $value): bool => $value !== null),
            ));
        });
    }
}
