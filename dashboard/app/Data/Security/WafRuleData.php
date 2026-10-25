<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * A CRS rule id with its message and category.
 */
final class WafRuleData extends Data
{
    public function __construct(
        public int $id,
        public string $message,
        public string $category,
    ) {}
}
