<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * What the scheduler would do with draft scaling settings (Settings →
 * Scaling's live preview). Read-only: nothing is placed or saved.
 */
final class ScalingPreviewData extends Data
{
    /**
     * @param  list<PlacementPreviewServerData>  $servers
     */
    public function __construct(
        public array $servers,
        /** Why the draft can't be placed (null: it fits). */
        public ?string $error,
    ) {}
}
