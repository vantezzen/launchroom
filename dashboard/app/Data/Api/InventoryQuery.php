<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * `GET /api/v1/security/inventory?q=` (docs/22 §11): words match package
 * names, a version-like token filters versions, `ecosystem:`, `service:`
 * and `scope:` filter.
 */
final class InventoryQuery extends Data
{
    public function __construct(
        #[Max(200)]
        public string $q = '',
    ) {}
}
