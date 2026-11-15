<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Data\Transfers\ImportInspectionData;
use App\Enums\DaemonTaskStatus;
use App\Models\DaemonTask;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Support\PublicId;
use Illuminate\Support\Facades\Cache;

/**
 * Inspection tasks are remembered for a few minutes per service instance
 * and user (docs/14 §12.5); only that user reads the result back.
 */
final class ImportInspections
{
    public function remember(DaemonTask $task, ServiceInstance $instance, User $user): void
    {
        Cache::put($this->key($task->public_id), ['instance' => $instance->id, 'user' => $user->id, 'task' => $task->id], config()->integer('launchroom.transfers.inspection_ttl_seconds'));
    }

    public function read(string $taskPublicId, ServiceInstance $instance, User $user): ?ImportInspectionData
    {
        if (! PublicId::isValid($taskPublicId, 'tsk')) {
            return null;
        }

        $entry = Cache::get($this->key($taskPublicId));

        if (! is_array($entry) || ($entry['instance'] ?? null) !== $instance->id || ($entry['user'] ?? null) !== $user->id) {
            return null;
        }

        $task = DaemonTask::query()->whereKey($entry['task'] ?? 0)->where('team_id', $instance->team_id)->first();

        if (! $task instanceof DaemonTask) {
            return null;
        }

        if ($task->status === DaemonTaskStatus::Failed || $task->status === DaemonTaskStatus::Cancelled) {
            return new ImportInspectionData($taskPublicId, 'failed', error: $task->error ?? 'The check didn’t finish. Try again.');
        }

        if ($task->status !== DaemonTaskStatus::Succeeded) {
            return new ImportInspectionData($taskPublicId, 'pending');
        }

        $result = $task->result?->dataImport;
        $owner = is_array($result?->rootOwner) ? $result->rootOwner : [];

        return new ImportInspectionData(
            $taskPublicId,
            'done',
            $result?->empty === true,
            is_int($result?->tables) ? $result->tables : 0,
            is_int($result?->sizeBytes) ? $result->sizeBytes : 0,
            is_string($result?->serverVersion) ? $result->serverVersion : null,
            is_int($owner['uid'] ?? null) ? $owner['uid'] : null,
            is_int($owner['gid'] ?? null) ? $owner['gid'] : null,
            is_string($result?->sourceVersion) ? $result->sourceVersion : null,
        );
    }

    private function key(string $taskPublicId): string
    {
        return 'transfers:inspection:'.$taskPublicId;
    }
}
