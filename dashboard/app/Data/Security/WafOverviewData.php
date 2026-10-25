<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * The Security page's web firewall section (docs/22 §9.2 part 3).
 */
final class WafOverviewData extends Data
{
    /**
     * @param  list<string>  $timestamps  bucket starts (UTC)
     * @param  list<WafSeriesData>  $series  sqli, xss, rce, scanner, other (blocked) and detected
     * @param  list<WafCategoryCountData>  $categories
     * @param  list<WafServiceCountData>  $services  most targeted
     * @param  list<WafServiceCountData>  $watching  services in Log only
     * @param  list<WafEventData>  $recent
     * @param  list<WafEdgeProblemData>  $edges
     */
    public function __construct(
        public string $range,
        public int $bucketSeconds,
        public array $timestamps,
        public array $series,
        public int $blocked,
        public int $detected,
        public array $categories,
        public array $services,
        public array $watching,
        public array $recent,
        public array $edges,
        public ?string $ruleSet,
        public bool $networksVisible,
    ) {}
}
