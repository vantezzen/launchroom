<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * A finding's page or sheet (docs/22 §9.3).
 */
final class FindingDetailData extends Data
{
    /**
     * @param  list<SecurityServiceRefData>  $otherServices  more services with this version
     * @param  list<string>  $domains
     * @param  list<FindingCheckData>  $checks
     */
    public function __construct(
        public FindingCardData $finding,
        public string $kind,
        public string $scope,
        public string $presence,
        public bool $direct,
        public ?string $lockfile,
        public array $otherServices,
        public array $domains,
        public array $checks,
        public ?string $dismissedReason,
        public ?string $dismissedNote,
        public ?string $dismissedBy,
        public ?string $dismissedAt,
        public ?AdvisoryDetailData $advisory,
        public ?InvestigationDetailData $investigation,
        public string $wafMode,
        public bool $canDismiss,
        public bool $canInvestigate,
        public bool $canChangeFirewall,
        public string $firewallUrl,
    ) {}
}
