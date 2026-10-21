<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Enums\Alerts\AlertDeliveryEvent;
use App\Models\Alerts\AlertChannel;
use App\Models\Alerts\AlertDelivery;

/**
 * "Send a test" (docs/15 §3.6): one test message, sent now so Settings can
 * say right away whether it arrived. Recorded like any delivery.
 */
final readonly class SendTestNotification
{
    public function __construct(
        private DeliverAlertNotification $deliver,
    ) {}

    public function handle(AlertChannel $channel): AlertDelivery
    {
        $delivery = new AlertDelivery(['alert_id' => null, 'channel_id' => $channel->id, 'event' => AlertDeliveryEvent::Test]);
        $delivery->save();

        $this->deliver->handle($delivery->id, final: true);

        return $delivery->refresh();
    }
}
