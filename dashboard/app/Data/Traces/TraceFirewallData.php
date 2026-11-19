<?php

declare(strict_types=1);

namespace App\Data\Traces;

use App\Data\Security\WafRuleData;
use App\Enums\Security\WafMode;
use App\Enums\Traces\TraceFirewallOutcome;
use Spatie\LaravelData\Data;

/**
 * What the web firewall decided about a traced request (docs/23 §2).
 */
final class TraceFirewallData extends Data
{
    /**
     * @param  list<WafRuleData>  $rules
     */
    public function __construct(
        public WafMode $mode,
        public TraceFirewallOutcome $outcome,
        /** Inbound anomaly score, capped at 255. */
        public int $score,
        public array $rules,
    ) {}
}
