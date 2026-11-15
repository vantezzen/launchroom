<?php

declare(strict_types=1);

namespace App\Services\Alerts\Senders;

use App\Data\Alerts\AlertMessage;
use App\Models\Alerts\AlertChannel;
use App\Services\Alerts\DeliveryFailed;

interface AlertSender
{
    /**
     * @throws DeliveryFailed
     */
    public function send(AlertChannel $channel, AlertMessage $message, string $deliveryId): void;
}
