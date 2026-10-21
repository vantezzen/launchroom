<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Enums\Alerts\AlertDeliveryEvent;
use App\Enums\Alerts\AlertDeliveryStatus;
use App\Models\Alerts\AlertDelivery;
use App\Services\Alerts\AlertMessages;
use App\Services\Alerts\DeliveryFailed;
use App\Services\Alerts\Senders\AlertSenders;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one delivery to its channel and records the outcome on the delivery
 * and the channel (docs/15 §3.3). Error texts are safe by construction
 * (DeliveryFailed); anything unexpected is stored as a generic sentence and
 * logged by class only, so a URL with its token is never persisted.
 *
 * Returns false when the attempt failed (the job retries unless $final).
 */
final readonly class DeliverAlertNotification
{
    public function __construct(
        private AlertSenders $senders,
        private AlertMessages $messages,
    ) {}

    public function handle(int $deliveryId, bool $final): bool
    {
        $delivery = AlertDelivery::query()
            ->whereKey($deliveryId)
            ->with(['channel.team', 'alert.project', 'alert.environment', 'alert.serviceInstance.service', 'alert.server'])
            ->first();

        if (! $delivery instanceof AlertDelivery || $delivery->status !== AlertDeliveryStatus::Pending) {
            return true;
        }

        $channel = $delivery->channel;

        if (! $channel->enabled && $delivery->event !== AlertDeliveryEvent::Test) {
            $delivery->transitionTo(AlertDeliveryStatus::Failed, 'The channel is paused.');

            return true;
        }

        $message = $delivery->alert === null
            ? $this->messages->test($channel->team)
            : $this->messages->forAlert($delivery->alert, $delivery->event, $channel->team);

        $delivery->attempts++;

        try {
            $this->senders->for($channel->kind)->send($channel, $message, $delivery->public_id);
        } catch (Throwable $e) {
            $error = match (true) {
                $e instanceof DeliveryFailed => $e->getMessage(),
                $e instanceof DecryptionFailed => 'The channel\'s address could not be decrypted. Add it again.',
                default => 'Sending failed unexpectedly.',
            };

            if (! $e instanceof DeliveryFailed) {
                Log::warning('alert delivery failed', ['delivery' => $delivery->public_id, 'kind' => $channel->kind->value, 'exception' => $e::class]);
            }

            if ($final) {
                $delivery->transitionTo(AlertDeliveryStatus::Failed, $error);
                $channel->recordDelivery(false, $error);
            } else {
                $delivery->error = mb_substr($error, 0, 500);
                $delivery->save();
            }

            return false;
        }

        $delivery->transitionTo(AlertDeliveryStatus::Sent);
        $channel->recordDelivery(true);

        return true;
    }
}
