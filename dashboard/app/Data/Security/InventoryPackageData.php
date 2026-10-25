<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * Search results grouped by package name (docs/22 §9.2 part 5).
 */
final class InventoryPackageData extends Data
{
    /**
     * @param  list<InventoryUseData>  $versions
     */
    public function __construct(
        public string $name,
        public string $ecosystem,
        public array $versions,
    ) {}
}
