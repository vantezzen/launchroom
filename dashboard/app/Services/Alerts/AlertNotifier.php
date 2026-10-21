<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Enums\Alerts\AlertDeliveryEvent;
use App\Enums\Alerts\AlertDeliveryStatus;
use App\Jobs\Alerts\DeliverAlertJob;
use App\Models\Alerts\Alert;
use App\Models\Alerts\AlertChannel;
use App\Models\Alerts\AlertDelivery;

/**
 * Queues one delivery per channel that wants an alert (docs/15 §3.3): every
 * enabled channel at or above its minimum severity, or, for "resolved", the
 * channels that were told about it. Jobs dispatch after the surrounding
 * transaction commits.
 */
final readonly class AlertNotifier
{
    /**
     * @return int deliveries queued
     */
    public function queue(Alert $alert, AlertDeliveryEvent $event): int
    {
        $channels = AlertChannel::query()
            ->where('team_id', $alert->team_id)
            ->where('enabled', true)
            ->orderBy('id')
            ->get()
            ->filter(static fn (AlertChannel $channel): bool => $channel->wants($alert->severity));

        if ($event === AlertDeliveryEvent::Resolved) {
            $told = AlertDelivery::query()
                ->where('alert_id', $alert->id)
                ->whereIn('event', [AlertDeliveryEvent::Fired->value, AlertDeliveryEvent::Repeated->value])
                ->whereIn('status', [AlertDeliveryStatus::Sent->value, AlertDeliveryStatus::Pending->value])
                ->pluck('channel_id')
                ->all();
            $channels = AlertChannel::query()->where('team_id', $alert->team_id)->where('enabled', true)->whereIn('id', $told)->orderBy('id')->get();
        }

        $count = 0;

        foreach ($channels as $channel) {
            $delivery = new AlertDelivery(['alert_id' => $alert->id, 'channel_id' => $channel->id, 'event' => $event]);
            $delivery->save();
            dispatch(new DeliverAlertJob($delivery->id));
            $count++;
        }

        return $count;
    }
}
