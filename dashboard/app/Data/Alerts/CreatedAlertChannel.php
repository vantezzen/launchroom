<?php

declare(strict_types=1);

namespace App\Data\Alerts;

use App\Models\Alerts\AlertChannel;
use SensitiveParameter;

/**
 * A new channel and, for webhooks, its signing secret: shown once, never again.
 */
final readonly class CreatedAlertChannel
{
    public function __construct(
        public AlertChannel $channel,
        #[SensitiveParameter]
        public ?string $signingSecret,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['channel' => $this->channel->public_id, 'signingSecret' => '[redacted]'];
    }
}
