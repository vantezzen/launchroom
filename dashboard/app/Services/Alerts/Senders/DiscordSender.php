<?php

declare(strict_types=1);

namespace App\Services\Alerts\Senders;

use App\Data\Alerts\AlertMessage;
use App\Enums\Alerts\AlertDeliveryEvent;
use App\Enums\Alerts\AlertSeverity;
use App\Models\Alerts\AlertChannel;
use App\Services\Alerts\ChannelSecret;
use App\Services\Alerts\DeliveryFailed;
use App\Services\Crypto\SealedColumns;
use App\Services\Http\Exceptions\WebhookPostFailed;
use App\Services\Http\SignedWebhookPoster;
use App\Support\Alerts\ChannelUrls;

/**
 * Discord webhook (docs/15 §3.5): one embed (headline, summary, link, facts
 * as inline fields, time), no mentions ever.
 */
final readonly class DiscordSender implements AlertSender
{
    public function __construct(
        private SealedColumns $sealed,
        private SignedWebhookPoster $poster,
    ) {}

    public function send(AlertChannel $channel, AlertMessage $message, string $deliveryId): void
    {
        $url = ChannelSecret::of($this->sealed->revealJson($channel, 'secret'))->url;

        if (! ChannelUrls::isDiscord($url)) {
            throw DeliveryFailed::because('This is not a Discord webhook address.');
        }

        try {
            $this->poster->postUnsigned($url, json_encode(self::payload($message), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (WebhookPostFailed $e) {
            throw DeliveryFailed::from($e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(AlertMessage $message): array
    {
        $fields = [];

        foreach (array_slice($message->facts, 0, 8, true) as $label => $value) {
            $fields[] = ['name' => mb_substr($label, 0, 256), 'value' => mb_substr($value, 0, 1024), 'inline' => true];
        }

        $embed = [
            'title' => mb_substr($message->headline(), 0, 256),
            'description' => mb_substr($message->summary, 0, 4096),
            'url' => $message->url,
            // The side bar carries the state, with the words in the title.
            'color' => match (true) {
                $message->event === AlertDeliveryEvent::Resolved => 0x107D32,
                $message->event === AlertDeliveryEvent::Test => 0x0062D1,
                $message->severity === AlertSeverity::Critical => 0xC4211B,
                default => 0xA35200,
            },
            'timestamp' => $message->payload['sentAt'] ?? null,
        ];

        if ($fields !== []) {
            $embed['fields'] = $fields;
        }

        if ($message->context !== []) {
            $embed['footer'] = ['text' => implode(' / ', $message->context)];
        }

        return ['username' => 'launchroom', 'embeds' => [$embed], 'allowed_mentions' => ['parse' => []]];
    }
}
