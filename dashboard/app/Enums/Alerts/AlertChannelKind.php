<?php

declare(strict_types=1);

namespace App\Enums\Alerts;

/**
 * Where a team hears about alerts (docs/15 §3.5).
 */
enum AlertChannelKind: string
{
    case Email = 'email';
    case Slack = 'slack';
    case Discord = 'discord';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Slack => 'Slack',
            self::Discord => 'Discord',
            self::Webhook => 'Webhook',
        };
    }

    /**
     * Kinds that keep an encrypted URL (and, for webhooks, a signing secret).
     */
    public function hasSecret(): bool
    {
        return $this !== self::Email;
    }
}
