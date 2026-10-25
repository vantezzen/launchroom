<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

final class WafCategoryCountData extends Data
{
    public function __construct(
        public string $category,
        public string $label,
        public int $blocked,
        public int $detected,
    ) {}
}
