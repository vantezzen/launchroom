<?php

declare(strict_types=1);

namespace App\Actions\AppUpdates;

use App\Models\AppUpdates\AppUpdate;

/**
 * The minute tick of app updates (docs/04 §14.2): every open update moves
 * along (in case an event was missed), then automatic updates whose window
 * is open start. Returns how many updates are open.
 */
final readonly class AdvanceAppUpdates
{
    public function __construct(
        private AdvanceAppUpdate $advance,
        private StartAutomaticUpdates $automatic,
    ) {}

    public function handle(): int
    {
        /** @var list<int> $ids */
        $ids = AppUpdate::query()->open()->orderBy('id')->pluck('id')->all();

        foreach ($ids as $id) {
            $this->advance->handle($id);
        }

        $this->automatic->handle();

        return AppUpdate::query()->open()->count();
    }
}
