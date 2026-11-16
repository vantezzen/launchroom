<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * Everything the Security page shows (docs/22 §9.2), refetched every
 * minute.
 */
final class SecurityPageData extends Data
{
    /**
     * @param  list<FeedStateData>  $feeds
     * @param  list<FindingCardData>  $actionNeeded
     * @param  list<WorthKnowingGroupData>  $worthKnowing
     * @param  list<RuntimeCardData>  $runtimes
     * @param  list<RuntimeCardData>  $baseImages
     * @param  list<string>  $notices  inventories that couldn't be read, sources failing for days (Worth knowing)
     * @param  list<FindingCardData>  $dismissedFindings  marked not affected, newest first (at most 50)
     * @param  list<FindingCardData>  $fixedFindings  resolved in the last 30 days, newest first (at most 50)
     */
    public function __construct(
        public bool $hasServices,
        public ?string $checkedAt,
        public bool $checksOff,
        public array $feeds,
        public bool $canCheckNow,
        public ?string $nextCheckAt,
        public bool $canManageSources,
        /** Change firewalls, dismiss and investigate (`projects.manage`). */
        public bool $canManage,
        public SecuritySummaryData $summary,
        public array $actionNeeded,
        public WafOverviewData $firewall,
        public array $worthKnowing,
        public array $runtimes,
        public array $baseImages,
        public array $notices,
        public int $dismissed,
        /** The project or service the page is narrowed to; null for the whole team. */
        public ?SecurityScopeData $scope = null,
        public array $dismissedFindings = [],
        public array $fixedFindings = [],
    ) {}
}
