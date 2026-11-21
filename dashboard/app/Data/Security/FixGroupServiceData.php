<?php

declare(strict_types=1);

namespace App\Data\Security;

use App\Enums\Security\Severity;
use Spatie\LaravelData\Data;

/**
 * One affected service of a fix group (docs/22 §9.2 part 2): its version,
 * the version that fixes it, its findings and the latest investigation.
 */
final class FixGroupServiceData extends Data
{
    /**
     * @param  list<string>  $findings  public ids of its open findings in the group
     */
    public function __construct(
        public SecurityServiceRefData $service,
        public bool $production,
        public string $version,
        public ?string $fixedVersion,
        public Severity $severity,
        public bool $knownExploited,
        public array $findings,
        /** The finding its row opens: its most severe one. */
        public string $lead,
        public ?FindingInvestigationData $investigation,
    ) {}
}
