<?php

declare(strict_types=1);

namespace App\Data\Alerts;

use App\Enums\Alerts\AlertChannelKind;
use App\Enums\Alerts\AlertSeverity;
use App\Models\Alerts\AlertChannel;
use Spatie\LaravelData\Data;

/**
 * A channel as Settings shows it. Never the URL or signing secret: only the
 * hint (host + last 4 characters).
 */
final class AlertChannelData extends Data
{
    /**
     * @param  list<string>  $recipients
     */
    public function __construct(
        public string $id,
        public AlertChannelKind $kind,
        public string $name,
        public ?string $hint,
        public array $recipients,
        public bool $enabled,
        public AlertSeverity $minSeverity,
        public ?string $lastDeliveryAt,
        public ?bool $lastDeliveryOk,
        public ?string $lastError,
        public bool $canManage,
    ) {}

    public static function fromChannel(AlertChannel $channel, bool $canManage): self
    {
        return new self(
            $channel->public_id,
            $channel->kind,
            $channel->name,
            $channel->target_hint,
            $channel->recipients ?? [],
            $channel->enabled,
            $channel->min_severity,
            $channel->last_delivery_at?->toIso8601String(),
            $channel->last_delivery_ok,
            $channel->last_error,
            $canManage,
        );
    }
}
