<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Data\Promotions\PromotionItem;
use App\Data\Promotions\PromotionSnapshot;
use App\Data\Specs\EnvironmentSpec;
use App\Models\EnvironmentPromotion;

/**
 * Everything one comparison of two environments computed (PromotionPlanner).
 */
final readonly class PromotionPlan
{
    /**
     * @param  list<PromotionItem>  $items
     */
    public function __construct(
        public EnvironmentSpec $sourceSpec,
        public EnvironmentSpec $targetSpec,
        public PromotionSnapshot $source,
        public PromotionSnapshot $target,
        public ?PromotionSnapshot $base,
        public ?EnvironmentPromotion $lastPromotion,
        public array $items,
    ) {}

    public function item(string $path): ?PromotionItem
    {
        foreach ($this->items as $item) {
            if ($item->path === $path) {
                return $item;
            }
        }

        return null;
    }
}
