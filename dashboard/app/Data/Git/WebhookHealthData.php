<?php

declare(strict_types=1);

namespace App\Data\Git;

use App\Enums\Git\WebhookDeliveryStatus;
use App\Enums\Git\WebhookHealth;
use App\Models\Git\GitHubApp;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

final class WebhookHealthData extends Data
{
    public function __construct(
        public WebhookHealth $health,
        public ?CarbonInterface $lastReceivedAt,
        public ?string $lastEvent,
        public ?WebhookDeliveryStatus $lastStatus,
    ) {}

    public static function fromApp(GitHubApp $app): self
    {
        $latest = $app->latestDelivery;

        return new self(
            match (true) {
                $app->webhook_last_received_at === null => WebhookHealth::Never,
                $app->hasHealthyWebhooks() => WebhookHealth::Healthy,
                default => WebhookHealth::Stale,
            },
            $app->webhook_last_received_at,
            $latest?->event,
            $latest?->status,
        );
    }
}
