<?php

declare(strict_types=1);

namespace App\Data\Alerts;

use App\Enums\Alerts\AlertRule;
use Spatie\LaravelData\Data;

/**
 * One rule's settings (bounds are checked against the rule by UpdateAlertRules).
 */
final class AlertRuleInput extends Data
{
    public function __construct(
        public AlertRule $rule,
        public bool $enabled,
        public ?float $threshold = null,
        public ?int $duration = null,
    ) {}
}
