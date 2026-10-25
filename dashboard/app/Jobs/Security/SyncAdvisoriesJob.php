<?php

declare(strict_types=1);

namespace App\Jobs\Security;

use App\Actions\Security\SyncAdvisories;
use App\Models\Security\ImageInventory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The OSV check (docs/22 §4.3): of one new inventory (CheckInventoryAdvisories)
 * or of every live inventory (`launchroom:security:sync`, Check now). A run
 * paused by a rate limit queues itself again after the back-off.
 */
#[Timeout(3500)]
#[Tries(1)]
#[UniqueFor(3600)]
final class SyncAdvisoriesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly ?int $inventoryId = null,
        public readonly bool $force = false,
    ) {}

    public function uniqueId(): string
    {
        return ($this->inventoryId ?? 'all').($this->force ? ':force' : '');
    }

    public function handle(SyncAdvisories $sync): void
    {
        $inventory = $this->inventoryId === null ? null : ImageInventory::query()->whereKey($this->inventoryId)->first();

        if ($this->inventoryId !== null && ! $inventory instanceof ImageInventory) {
            return;
        }

        $result = $sync->handle($inventory, $this->force);

        if (! $result['complete'] && $result['retryAfter'] !== null) {
            dispatch(new self($this->inventoryId))->delay(now()->addSeconds($result['retryAfter']));
        }
    }
}
