<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * One version of a package and where it runs (docs/22 §9.2 part 5).
 */
final class InventoryUseData extends Data
{
    /**
     * @param  list<SecurityServiceRefData>  $services
     * @param  list<FindingCardData>  $findings  open findings of this version
     */
    public function __construct(
        public string $version,
        /** `runtime`, `dev`, `client` or `unknown` (the widest seen). */
        public string $scope,
        public string $kind,
        public array $services,
        public array $findings,
    ) {}
}
