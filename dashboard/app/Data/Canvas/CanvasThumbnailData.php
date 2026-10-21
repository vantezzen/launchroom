<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * The production canvas of a project in miniature (direction.md §5.2
 * ProjectCard), from `canvas_layouts`. Node centres are normalised to 0–1;
 * `aspectRatio` (width / height of the laid-out canvas incl. node size) lets
 * the card keep proportions. `positioned` is false when some nodes were never
 * placed by hand and got a fallback grid position.
 */
final class CanvasThumbnailData extends Data
{
    /**
     * @param  list<CanvasThumbnailNodeData>  $nodes  at most 24
     * @param  list<CanvasThumbnailEdgeData>  $edges
     */
    public function __construct(
        public array $nodes,
        public array $edges,
        public float $aspectRatio,
        public bool $positioned,
    ) {}
}
