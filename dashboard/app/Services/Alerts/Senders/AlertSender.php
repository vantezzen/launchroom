<?php

declare(strict_types=1);

namespace App\Services\Alerts\Senders;

use App\Models\Alerts\AlertChannel;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\DeliveryFailed;

/**
 * Sends one message to one channel of its kind; throws on failure.
 */
interface AlertSender
{
    /**
     * @throws DeliveryFailed
     */
    public function send(AlertChannel $channel, AlertMessage $message, string $deliveryId): void;
}
