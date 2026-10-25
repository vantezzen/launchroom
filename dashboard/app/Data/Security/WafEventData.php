<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * One request that reached the firewall's anomaly threshold (docs/22 §6.5).
 * `clientNetwork` (a /24 or /48, never an address) only for owners and
 * admins.
 */
final class WafEventData extends Data
{
    /**
     * @param  list<WafRuleData>  $rules
     * @param  list<string>  $categories
     * @param  list<string>  $targets
     */
    public function __construct(
        public string $id,
        public string $at,
        /** `blocked` or `detected`. */
        public string $action,
        public string $method,
        public string $path,
        public array $rules,
        public array $categories,
        public array $targets,
        public ?string $clientNetwork,
        public int $score,
        public string $reference,
        public SecurityServiceRefData $service,
        /** The firewall exception "Allow requests like this" would add (docs/22 §6.4). */
        public ?WafExclusionDraftData $exclusion,
    ) {}
}
