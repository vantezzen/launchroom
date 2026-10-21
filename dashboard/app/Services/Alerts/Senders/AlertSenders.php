<?php

declare(strict_types=1);

namespace App\Services\Alerts\Senders;

use App\Enums\Alerts\AlertChannelKind;
use Illuminate\Contracts\Container\Container;

/**
 * The sender of each channel kind.
 */
final readonly class AlertSenders
{
    public function __construct(
        private Container $container,
    ) {}

    public function for(AlertChannelKind $kind): AlertSender
    {
        return $this->container->make(match ($kind) {
            AlertChannelKind::Email => EmailSender::class,
            AlertChannelKind::Slack => SlackSender::class,
            AlertChannelKind::Discord => DiscordSender::class,
            AlertChannelKind::Webhook => WebhookSender::class,
        });
    }
}
