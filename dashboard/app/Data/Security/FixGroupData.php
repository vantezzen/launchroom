<?php

declare(strict_types=1);

namespace App\Data\Security;

use App\Enums\Security\Severity;
use Spatie\LaravelData\Data;

/**
 * One thing to fix on the Security page (docs/22 §5.6, §9.2 part 2): every
 * open "Action needed" finding of one package, with the update that fixes
 * them, what an attacker could do, the affected services and the
 * advisories. Built by App\Queries\Security\FixGroups.
 */
final class FixGroupData extends Data
{
    /**
     * @param  list<string>  $versions  installed versions, most used first
     * @param  list<FixGroupServiceData>  $services  production first, then by severity
     * @param  list<FixGroupAdvisoryData>  $advisories  known exploited first, then by severity
     */
    public function __construct(
        /** Stable across evaluations (`fix_` + 16 hex), also the page anchor. */
        public string $key,
        public string $ecosystem,
        public string $package,
        public array $versions,
        /** The version that fixes every advisory in every service, when known. */
        public ?string $fixedVersion,
        public Severity $severity,
        public ?float $cvss,
        public bool $knownExploited,
        public ?string $knownExploitedSince,
        /** "Attackers could run code on the server or …", from the advisories' CWEs. */
        public ?string $impact,
        /** The most severe advisory's summary, for when no impact is known. */
        public ?string $headline,
        /** At least one affected service runs in a production environment. */
        public bool $production,
        public array $services,
        public array $advisories,
        /** The finding "See the fix" opens: the most severe one of the first service. */
        public string $lead,
    ) {}
}
