<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Enums\Alerts\AlertRule;

/**
 * A rule with a team's overrides applied (docs/15 §3.4).
 */
final readonly class ResolvedRule
{
    public function __construct(
        public AlertRule $rule,
        public bool $enabled,
        public ?float $threshold,
        public ?int $duration,
    ) {}

    public static function defaults(AlertRule $rule): self
    {
        return new self($rule, true, $rule->threshold()['default'] ?? null, $rule->duration()['default'] ?? null);
    }
}
