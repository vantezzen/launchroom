<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Enums\Alerts\AlertDeliveryEvent;
use App\Enums\Alerts\AlertSeverity;

/**
 * What one delivery says, independent of the channel (docs/15 §3.5).
 * `payload` is the webhook event body (also the source of the other formats).
 */
final readonly class AlertMessage
{
    /**
     * @param  array<string, string>  $facts
     * @param  list<string>  $context  "shop · production · fra-1"
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public AlertDeliveryEvent $event,
        public string $title,
        public string $summary,
        public ?AlertSeverity $severity,
        public string $url,
        public array $facts,
        public array $context,
        public array $payload,
    ) {}

    /** Headline with the event's lead-in ("Resolved: web failed to deploy"). */
    public function headline(): string
    {
        return match ($this->event) {
            AlertDeliveryEvent::Resolved => 'Resolved: '.$this->title,
            AlertDeliveryEvent::Repeated => 'Again: '.$this->title,
            default => $this->title,
        };
    }
}
