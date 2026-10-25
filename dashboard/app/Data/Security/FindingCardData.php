<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * One finding on the Security page (docs/22 §9.2): the service, the
 * package and its fix, the advisory and its severity, the investigation
 * line.
 */
final class FindingCardData extends Data
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public string $id,
        public string $href,
        public string $advisory,
        public ?string $title,
        /** `critical`, `high`, `medium`, `low` or `unknown`. */
        public string $severity,
        public ?float $cvss,
        public bool $knownExploited,
        public ?string $knownExploitedSince,
        public bool $public,
        public string $ecosystem,
        public string $package,
        public string $version,
        public ?string $fixedVersion,
        /** `action_needed` or `worth_knowing`. */
        public string $level,
        public array $reasons,
        /** `open` or `dismissed`. */
        public string $status,
        public string $firstSeenAt,
        public SecurityServiceRefData $service,
        public ?FindingInvestigationData $investigation,
    ) {}
}
