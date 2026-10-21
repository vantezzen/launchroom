<?php

declare(strict_types=1);

namespace App\Actions\Daemons;

use App\Enums\DaemonTaskStatus;
use App\Models\DaemonTask;
use Illuminate\Support\Facades\DB;

/**
 * Tasks whose lease expired go back to the queue for another attempt, or
 * fail once out of attempts (docs/03 §7).
 */
final class RequeueExpiredLeases
{
    public function handle(): int
    {
        return DB::transaction(function (): int {
            $tasks = DaemonTask::query()
                ->where('status', DaemonTaskStatus::Leased->value)
                ->where('lease_expires_at', '<', now())
                ->lock('for update skip locked')
                ->limit(100)
                ->get();

            foreach ($tasks as $task) {
                if ($task->attempt >= $task->max_attempts || ($task->deadline_at?->isPast() ?? false)) {
                    $task->forceFill(['error' => 'Lease expired on the last attempt.']);
                    $task->transitionTo(DaemonTaskStatus::Failed);

                    continue;
                }

                $task->forceFill([
                    'lease_expires_at' => null,
                    'server_id' => $task->pinned_to_server ? $task->server_id : null,
                ]);
                $task->transitionTo(DaemonTaskStatus::Pending);
            }

            return $tasks->count();
        });
    }
}
