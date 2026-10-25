<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * A "Worth knowing" group by reason (docs/22 §5.4, §9.2), most severe first.
 */
final class WorthKnowingGroupData extends Data
{
    /**
     * @param  list<WorthKnowingItemData>  $items
     */
    public function __construct(
        public string $reason,
        public string $label,
        public int $count,
        public array $items,
    ) {}
}
