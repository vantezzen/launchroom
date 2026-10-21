<?php

declare(strict_types=1);

namespace App\Services\Alerts\Senders;

use App\Models\Alerts\AlertChannel;
use App\Services\Alerts\AlertChannelSecrets;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\DeliveryFailed;
use App\Support\Alerts\ChannelUrls;
use App\Support\Alerts\WebhookSignature;
use Carbon\CarbonImmutable;

/**
 * Generic signed webhook (docs/15 §3.5): the JSON event, encoded once; those
 * exact bytes are signed (HMAC-SHA256 over "<t>.<body>") and sent.
 */
final readonly class WebhookSender implements AlertSender
{
    public function __construct(
        private AlertChannelSecrets $secrets,
        private HttpPost $post,
    ) {}

    public function send(AlertChannel $channel, AlertMessage $message, string $deliveryId): void
    {
        $secret = $this->secrets->reveal($channel);

        if (! ChannelUrls::isWebhook($secret->url) || $secret->signingSecret === null || $secret->signingSecret === '') {
            // Fail closed: never send an unsigned event.
            throw DeliveryFailed::because('This webhook has no valid address or signing secret.');
        }

        $body = json_encode($message->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = CarbonImmutable::now()->getTimestamp();

        $this->post->send($secret->url, $body, [
            'X-Launchroom-Event' => $message->event->type(),
            'X-Launchroom-Delivery' => $deliveryId,
            'X-Launchroom-Signature' => WebhookSignature::header($secret->signingSecret, $timestamp, $body),
        ]);
    }
}
