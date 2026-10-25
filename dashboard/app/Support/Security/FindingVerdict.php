<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Enums\Security\FindingLevel;
use App\Enums\Security\FindingReason;

/**
 * The outcome of the alert-level rules: the level, the reasons in rule
 * order (the first one is shown) and every rule as a checklist line.
 */
final readonly class FindingVerdict
{
    /**
     * @param  list<FindingReason>  $reasons
     * @param  list<array{rule: string, passed: bool, label: string}>  $checks
     */
    public function __construct(
        public FindingLevel $level,
        public array $reasons,
        public array $checks,
    ) {}

    /**
     * @return list<string>
     */
    public function reasonCodes(): array
    {
        return array_map(static fn (FindingReason $reason): string => $reason->value, $this->reasons);
    }
}
