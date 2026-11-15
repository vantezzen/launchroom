<?php

declare(strict_types=1);

namespace App\Listeners\Previews;

use App\Enums\Previews\PreviewStatus;
use App\Events\Backups\RestoreRunFinished;
use App\Jobs\Previews\AdvancePreviewsJob;
use App\Models\Previews\Preview;

/**
 * A restore finished: previews of that team cloning a database continue
 * right away instead of at the next tick (docs/06 §5).
 */
final class AdvancePreviewsOnRestoreFinished
{
    public function handle(RestoreRunFinished $event): void
    {
        if ($event->teamId !== null && Preview::query()->where('team_id', $event->teamId)->where('status', PreviewStatus::Preparing->value)->exists()) {
            dispatch(new AdvancePreviewsJob($event->teamId));
        }
    }
}
