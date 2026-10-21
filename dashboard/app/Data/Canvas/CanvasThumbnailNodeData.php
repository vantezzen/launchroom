<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\ServiceKind;
use Spatie\LaravelData\Data;

/**
 * A node of a project card's mini canvas: kind and centre, normalised to 0–1
 * within the thumbnail's bounding box.
 */
final class CanvasThumbnailNodeData extends Data
{
    public function __construct(
        public string $id,
        public ServiceKind $kind,
        /** Glyph key (App\Support\Canvas\ServiceGlyph). */
        public string $logo,
        public float $x,
        public float $y,
        /** Display name of the service (labels/tooltips on larger thumbnails). */
        public ?string $name = null,
    ) {}
}
