<?php

declare(strict_types=1);

namespace App\Actions\RemoteAccess;

use App\Models\DaemonTask;
use App\Models\RemoteAccess\RemoteAccess;
use App\Models\User;
use App\Services\Tasks\TaskCancellation;
use Illuminate\Support\Facades\DB;

/**
 * Ends a temporary access: cancels its task (a pending one at once, a
 * running one on its next heartbeat, within 5 seconds). The row and the
 * "ended" audit entry are finished by FinishRemoteAccess when the task ends.
 */
final readonly class EndRemoteAccess
{
    public function __construct(
        private TaskCancellation $cancellation,
    ) {}

    public function handle(RemoteAccess $access, ?User $by): void
    {
        DB::transaction(function () use ($access, $by): void {
            $access = RemoteAccess::query()->whereKey($access->id)->lockForUpdate()->firstOrFail();

            if (! $access->status->isLive()) {
                return;
            }

            if ($by instanceof User && $access->ended_by_id === null) {
                $access->forceFill(['ended_by_id' => $by->id])->save();
            }

            $task = $access->daemonTask()->first();

            if ($task instanceof DaemonTask) {
                $this->cancellation->cancel($task);
            }
        });
    }
}
