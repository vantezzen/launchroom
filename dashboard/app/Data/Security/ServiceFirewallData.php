<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * The service panel's Web firewall settings page (docs/22 §9.6).
 */
final class ServiceFirewallData extends Data
{
    /**
     * @param  list<int>  $series  blocked plus detected per hour, last 7 days
     * @param  list<WafExclusionData>  $exclusions
     * @param  list<WafEdgeProblemData>  $edges
     * @param  list<WafEventData>  $recent
     */
    public function __construct(
        /** `off`, `log` or `block`. */
        public string $mode,
        public int $paranoia,
        /** Request body inspected: 32, 128 or 1024 KiB. */
        public int $inspectBodyKiB,
        public bool $routed,
        public bool $canManage,
        public int $blocked,
        public int $detected,
        public array $series,
        public ?WafServiceCountData $watching,
        public array $exclusions,
        public array $edges,
        public array $recent,
        public ?string $ruleSet,
        public string $updateUrl,
        public string $exclusionsUrl,
        public string $investigateUrl,
        public bool $canInvestigate,
    ) {}
}
