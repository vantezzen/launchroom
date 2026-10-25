<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Data\Security\WafCategoryCountData;
use App\Data\Security\WafServiceCountData;
use Spatie\LaravelData\Data;

/**
 * `GET /api/v1/security/waf?range=` (docs/22 §11): firewall totals, per
 * category and per service. No client networks.
 */
final class WafSummaryResource extends Data
{
    /**
     * @param  list<WafCategoryCountData>  $categories
     * @param  list<WafServiceCountData>  $services
     */
    public function __construct(
        public string $range,
        public int $blocked,
        public int $detected,
        public array $categories,
        public array $services,
    ) {}
}
