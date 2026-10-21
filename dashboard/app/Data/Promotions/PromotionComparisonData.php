<?php

declare(strict_types=1);

namespace App\Data\Promotions;

use Spatie\LaravelData\Data;

/**
 * The Compare & promote page (docs/06 §6.1, docs/08 §3.8).
 */
final class PromotionComparisonData extends Data
{
    /**
     * @param  list<PromotionItem>  $items
     * @param  list<PromotionImageData>  $images
     * @param  list<PromotionEnvironmentData>  $environments  every environment that can be compared
     * @param  list<PromotionServiceData>  $services  services of either side, for grouping
     */
    public function __construct(
        public PromotionEnvironmentData $from,
        public PromotionEnvironmentData $to,
        public array $environments,
        /** What the comparison is relative to, e.g. "since staging was created from production". */
        public string $baseDescription,
        public bool $hasBase,
        public array $items,
        public array $services,
        public array $images,
        public int $offered,
        public bool $secretsMustDiffer,
        public bool $canPromote,
        public bool $targetHasStagedChanges,
    ) {}
}
