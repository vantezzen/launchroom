<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * "Search packages and runtimes" (docs/22 §9.2 part 5).
 */
final class InventorySearchData extends Data
{
    /**
     * @param  list<InventoryPackageData>  $packages
     */
    public function __construct(
        public string $query,
        public array $packages,
        public bool $truncated,
    ) {}
}
