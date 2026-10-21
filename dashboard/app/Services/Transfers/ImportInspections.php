<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Data\Transfers\ImportInspectionData;
use App\Enums\DaemonTaskStatus;
use App\Models\DaemonTask;
use App\Models\ServiceInstance;
use App\Models\User;
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
        if (preg_match('/^tsk_[0-9A-HJKMNP-TV-Z]{26}$/D', $taskPublicId) !== 1) {
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

        $result = is_array($task->result['dataImport'] ?? null) ? $task->result['dataImport'] : [];
        $int = static fn (mixed $value): ?int => is_int($value) ? $value : null;
        $owner = is_array($result['rootOwner'] ?? null) ? $result['rootOwner'] : [];

        return new ImportInspectionData(
            $taskPublicId,
            'done',
            ($result['empty'] ?? false) === true,
            $int($result['tables'] ?? null) ?? 0,
            $int($result['sizeBytes'] ?? null) ?? 0,
            is_string($result['serverVersion'] ?? null) ? $result['serverVersion'] : null,
            $int($owner['uid'] ?? null),
            $int($owner['gid'] ?? null),
            is_string($result['sourceVersion'] ?? null) ? $result['sourceVersion'] : null,
        );
    }

    private function key(string $taskPublicId): string
    {
        return 'transfers:inspection:'.$taskPublicId;
    }
}
