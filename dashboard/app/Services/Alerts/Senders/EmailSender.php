<?php

declare(strict_types=1);

namespace App\Services\Alerts\Senders;

use App\Models\Alerts\AlertChannel;
use App\Notifications\AlertEmailNotification;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\DeliveryFailed;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Email through the instance's mail settings (docs/15 §3.5): one message
 * per address, sent now (we already run in a queued job or a test request).
 */
final readonly class EmailSender implements AlertSender
{
    public function send(AlertChannel $channel, AlertMessage $message, string $deliveryId): void
    {
        $recipients = $channel->recipients ?? [];

        if ($recipients === []) {
            throw DeliveryFailed::because('This channel has no email addresses.');
        }

        foreach ($recipients as $address) {
            try {
                Notification::route('mail', $address)->notifyNow(new AlertEmailNotification($message));
            } catch (Throwable) {
                throw DeliveryFailed::because('The mail server did not accept the message for '.$address.'.');
            }
        }
    }

    /**
     * Whether this launchroom can send email at all (Settings says so otherwise).
     */
    public static function configured(): bool
    {
        return ! in_array(config()->string('mail.default'), ['log', 'array'], true);
    }
}
