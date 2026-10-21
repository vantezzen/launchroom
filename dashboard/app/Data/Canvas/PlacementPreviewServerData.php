<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

final class PlacementPreviewServerData extends Data
{
    public function __construct(
        public string $id,
        public bool $eligible,
        public ?string $reason,
        public int $planned,
    ) {}
}
