<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Enums\Security\InventoryStatus;
use App\Models\DaemonTask;
use App\Models\Security\ImageInventory;

/**
 * An inventory task failed all its attempts (docs/22 §3.1): the inventory
 * is failed with the reason; BackfillInventories creates it again twice
 * (after 10 minutes and after an hour). Never blocks a deployment.
 */
final readonly class FailInventory
{
    public function handle(DaemonTask $task): void
    {
        $inventory = ImageInventory::query()
            ->where('team_id', $task->team_id)
            ->where('task_id', $task->id)
            ->where('status', InventoryStatus::Pending->value)
            ->first();

        if (! $inventory instanceof ImageInventory) {
            return;
        }

        $inventory->forceFill([
            'error' => mb_substr($task->error ?? 'The server couldn’t read the packages of this image.', 0, 1024),
            'failed_at' => now(),
        ]);
        $inventory->transitionTo(InventoryStatus::Failed);
    }
}
