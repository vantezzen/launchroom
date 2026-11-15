<?php

declare(strict_types=1);

namespace App\Services\Alerts\Senders;

use App\Data\Alerts\AlertMessage;
use App\Models\Alerts\AlertChannel;
use App\Services\Alerts\ChannelSecret;
use App\Services\Alerts\DeliveryFailed;
use App\Services\Crypto\SealedColumns;
use App\Services\Http\Exceptions\WebhookPostFailed;
use App\Services\Http\SignedWebhookPoster;
use App\Support\Alerts\ChannelUrls;

/**
 * Generic signed webhook (docs/15 §3.5): the JSON event, encoded once; those
 * exact bytes are signed and sent.
 */
final readonly class WebhookSender implements AlertSender
{
    public function __construct(
        private SealedColumns $sealed,
        private SignedWebhookPoster $poster,
    ) {}

    public function send(AlertChannel $channel, AlertMessage $message, string $deliveryId): void
    {
        $secret = ChannelSecret::of($this->sealed->revealJson($channel, 'secret'));

        if (! ChannelUrls::isWebhook($secret->url) || $secret->signingSecret === null || $secret->signingSecret === '') {
            // Fail closed: never send an unsigned event.
            throw DeliveryFailed::because('This webhook has no valid address or signing secret.');
        }

        $body = json_encode($message->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $this->poster->post($secret->url, $body, $message->event->type(), $deliveryId, $secret->signingSecret);
        } catch (WebhookPostFailed $e) {
            throw DeliveryFailed::from($e);
        }
    }
}
