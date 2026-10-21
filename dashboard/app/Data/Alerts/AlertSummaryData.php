<?php

declare(strict_types=1);

namespace App\Data\Alerts;

use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\Alerts\AlertStatus;
use App\Models\Alerts\Alert;
use Spatie\LaravelData\Data;

/**
 * An alert in a list (open alerts on the Notifications page).
 */
final class AlertSummaryData extends Data
{
    public function __construct(
        public string $id,
        public AlertRule $rule,
        public AlertSeverity $severity,
        public AlertStatus $status,
        public string $title,
        public string $summary,
        public ?string $href,
        public string $openedAt,
        public ?string $resolvedAt,
    ) {}

    public static function fromAlert(Alert $alert): self
    {
        return new self(
            $alert->public_id,
            $alert->rule,
            $alert->severity,
            $alert->status,
            $alert->title,
            $alert->summary,
            $alert->href,
            $alert->opened_at->toIso8601String(),
            $alert->resolved_at?->toIso8601String(),
        );
    }
}
