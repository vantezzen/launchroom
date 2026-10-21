<?php

declare(strict_types=1);

namespace App\Data\Alerts;

use App\Enums\Alerts\AlertRule;
use App\Services\Alerts\ResolvedRule;
use Spatie\LaravelData\Data;

/**
 * One row of "What we tell you about" with its bounds.
 */
final class AlertRuleData extends Data
{
    public function __construct(
        public AlertRule $rule,
        public string $label,
        public string $description,
        /** services | servers | data */
        public string $group,
        public bool $enabled,
        public ?float $threshold,
        public ?float $thresholdMin,
        public ?float $thresholdMax,
        /** '%' | 'days' */
        public ?string $thresholdUnit,
        public ?int $duration,
        public ?int $durationMin,
        public ?int $durationMax,
    ) {}

    public static function fromResolved(ResolvedRule $resolved): self
    {
        $rule = $resolved->rule;
        $threshold = $rule->threshold();
        $duration = $rule->duration();

        return new self(
            $rule,
            $rule->label(),
            $rule->description(),
            $rule->group(),
            $resolved->enabled,
            $resolved->threshold,
            $threshold['min'] ?? null,
            $threshold['max'] ?? null,
            $threshold['unit'] ?? null,
            $resolved->duration,
            $duration['min'] ?? null,
            $duration['max'] ?? null,
        );
    }
}
