<?php

declare(strict_types=1);

namespace App\Jobs\Alerts;

use App\Actions\Alerts\DeliverAlertNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Sends one queued delivery (docs/15 §3.2): up to 3 attempts, 30 s / 2 min /
 * 10 min apart, 30 s each. Only the id travels; the delivery row makes it
 * idempotent (a sent or failed delivery is never sent again).
 */
#[Backoff([30, 120, 600])]
#[Timeout(30)]
#[Tries(3)]
final class DeliverAlertJob implements ShouldQueue
{
    use Queueable;

    private const int TRIES = 3;

    /** Seconds before attempt 2 and 3 (the attributes above say the same for the worker). */
    private const array BACKOFF = [30, 120, 600];

    public function __construct(
        public readonly int $deliveryId,
    ) {
        $this->afterCommit();
    }

    public function handle(DeliverAlertNotification $deliver): void
    {
        $final = $this->attempts() >= self::TRIES;

        if (! $deliver->handle($this->deliveryId, $final) && ! $final) {
            // A failure is recorded, not thrown: the alert's outcome never
            // depends on a chat service (and the sync queue in tests stays quiet).
            $this->release(self::BACKOFF[min(max(0, $this->attempts() - 1), 2)]);
        }
    }
}
