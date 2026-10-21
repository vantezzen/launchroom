<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Enums\Alerts\AlertStatus;
use App\Models\Alerts\Alert;
use App\Models\Alerts\AlertDelivery;
use Carbon\CarbonImmutable;

/**
 * Retention (docs/15 §3.3): deliveries 90 days, resolved alerts a year.
 * Firing alerts are never pruned. Chunked, idempotent.
 */
final readonly class PruneAlerts
{
    public const int DELIVERY_DAYS = 90;

    public const int ALERT_DAYS = 365;

    /**
     * @return array{deliveries: int, alerts: int}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $deliveries = 0;
        $alerts = 0;

        do {
            $ids = AlertDelivery::query()->where('created_at', '<', $now->subDays(self::DELIVERY_DAYS))->limit(5000)->pluck('id')->all();
            $deliveries += count($ids);
            AlertDelivery::query()->whereKey($ids)->delete();
        } while (count($ids) === 5000);

        do {
            $ids = Alert::query()->where('status', AlertStatus::Resolved->value)->where('resolved_at', '<', $now->subDays(self::ALERT_DAYS))->limit(5000)->pluck('id')->all();
            $alerts += count($ids);
            Alert::query()->whereKey($ids)->delete();
        } while (count($ids) === 5000);

        return ['deliveries' => $deliveries, 'alerts' => $alerts];
    }
}
