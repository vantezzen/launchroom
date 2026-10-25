<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * An investigation's report (docs/22 §7.5): what was checked, what was
 * found (sentences with a level), the next steps and the honesty line.
 * Stored as `security_investigations.report`; no process commands, only
 * program paths, and client networks only in `network` (owners/admins).
 */
final class InvestigationReportData extends Data
{
    public const string HONESTY = 'Someone who got in can remove their traces, so a clean result doesn’t prove nothing happened. The firewall only sees requests that match its rules, and launchroom keeps request details for 7 days.';

    /**
     * @param  list<InvestigationCheckData>  $checked
     * @param  list<InvestigationLineData>  $found
     * @param  list<InvestigationStepData>  $steps
     * @param  list<string>  $summary  short sentences for notifications and cards (no paths or networks)
     */
    public function __construct(
        public array $checked,
        public array $found,
        public array $steps,
        public bool $partial,
        public array $summary,
        public string $honesty = self::HONESTY,
    ) {}
}
