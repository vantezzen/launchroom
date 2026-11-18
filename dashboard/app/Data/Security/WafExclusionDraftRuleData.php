<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * One exception of an "Allow requests like this" draft: a rule that scored
 * and, when the event shows which part of the request it matched, that part.
 */
final class WafExclusionDraftRuleData extends Data
{
    public function __construct(
        public int $rule,
        public string $message,
        public ?string $target,
    ) {}
}
