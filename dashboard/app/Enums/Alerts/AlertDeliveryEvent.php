<?php

declare(strict_types=1);

namespace App\Enums\Alerts;

/**
 * What a delivery tells a channel (docs/15 §3.3, webhook `type`).
 */
enum AlertDeliveryEvent: string
{
    case Fired = 'fired';
    case Repeated = 'repeated';
    case Resolved = 'resolved';
    case Test = 'test';

    /** The webhook event type (`alert.fired`, …). */
    public function type(): string
    {
        return 'alert.'.$this->value;
    }
}
