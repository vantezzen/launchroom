<?php

declare(strict_types=1);

namespace App\Services\Alerts\Senders;

use App\Enums\Alerts\AlertDeliveryEvent;
use App\Models\Alerts\AlertChannel;
use App\Services\Alerts\AlertChannelSecrets;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\DeliveryFailed;
use App\Support\Alerts\ChannelUrls;

/**
 * Slack incoming webhook (docs/15 §3.5): a fallback `text` plus blocks: the
 * headline in bold with the summary, facts as fields, a context line and a
 * button back to launchroom.
 */
final readonly class SlackSender implements AlertSender
{
    public function __construct(
        private AlertChannelSecrets $secrets,
        private HttpPost $post,
    ) {}

    public function send(AlertChannel $channel, AlertMessage $message, string $deliveryId): void
    {
        $url = $this->secrets->reveal($channel)->url;

        if (! ChannelUrls::isSlack($url)) {
            throw DeliveryFailed::because('This is not a Slack incoming webhook address.');
        }

        $this->post->send($url, json_encode(self::payload($message), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(AlertMessage $message): array
    {
        $icon = match (true) {
            $message->event === AlertDeliveryEvent::Resolved => ':white_check_mark:',
            $message->event === AlertDeliveryEvent::Test => ':wave:',
            default => ':warning:',
        };
        $blocks = [[
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => $icon.' *'.self::escape($message->headline()).'*'."\n".self::escape($message->summary)],
        ]];

        if ($message->facts !== []) {
            $fields = [];

            foreach ($message->facts as $label => $value) {
                $fields[] = ['type' => 'mrkdwn', 'text' => '*'.self::escape($label).'*'."\n".self::escape($value)];
            }

            $blocks[] = ['type' => 'section', 'fields' => array_slice($fields, 0, 10)];
        }

        if ($message->context !== []) {
            $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => self::escape(implode(' / ', $message->context))]]];
        }

        $blocks[] = ['type' => 'actions', 'elements' => [[
            'type' => 'button',
            'text' => ['type' => 'plain_text', 'text' => 'Open in launchroom'],
            'url' => $message->url,
        ]]];

        return ['text' => $message->headline(), 'blocks' => $blocks];
    }

    /** Slack mrkdwn needs only &, < and > escaped. */
    private static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
