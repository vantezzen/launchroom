<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use Spatie\LaravelData\Data;

/**
 * The picture of what a blueprint creates (docs/17 §4): its services in
 * Public · App · Data groups, the edges between them and the resources it
 * needs. Secret-free.
 */
final class BlueprintPreviewData extends Data
{
    /**
     * @param  list<BlueprintServicePreviewData>  $services
     * @param  list<BlueprintEdgeData>  $edges
     */
    public function __construct(
        public array $services,
        public array $edges,
        public float $cpus,
        public int $memoryMb,
        public int $volumes,
        public int $sharedVariables = 0,
    ) {}
}
