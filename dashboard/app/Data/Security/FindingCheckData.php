<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * One evaluated rule (A1–A6) of a finding, as a checklist line (docs/22 §9.3).
 */
final class FindingCheckData extends Data
{
    public function __construct(
        public string $rule,
        public bool $passed,
        public string $label,
    ) {}
}
