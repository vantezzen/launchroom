<?php

declare(strict_types=1);

namespace App\Actions\Commands;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Commands\CommandRunStatus;
use App\Models\Commands\CommandRun;
use App\Models\DaemonTask;
use App\Models\User;
use App\Services\Tasks\TaskCancellation;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a queued or running command (D105): a queued task is cancelled
 * at once; a running container gets SIGTERM and 10 seconds, then it is
 * removed (docs/03 §7.3). The run ends as cancelled when its task reports
 * back (FinishCommandRun). Cancelling an ended run changes nothing.
 */
final readonly class CancelCommandRun
{
    public function __construct(
        private TaskCancellation $cancellation,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(CommandRun $run, User $user, AuditActor $actor): CommandRun
    {
        DB::transaction(function () use ($run, $user, $actor): void {
            $locked = CommandRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal() || $locked->cancel_requested_at !== null) {
                return;
            }

            $locked->forceFill(['cancel_requested_at' => now(), 'cancelled_by_id' => $user->id])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::CommandCancelled,
                actor: $actor,
                teamId: $locked->team_id,
                subjectType: 'command_run',
                subjectId: $locked->public_id,
            ));

            $task = $locked->daemon_task_id === null ? null : DaemonTask::query()->find($locked->daemon_task_id);

            if ($task instanceof DaemonTask) {
                $this->cancellation->cancel($task);
            } else {
                $locked->transitionTo(CommandRunStatus::Cancelled);
            }
        });

        return $run->refresh();
    }
}
