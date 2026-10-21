<?php

declare(strict_types=1);

namespace App\Data\Git;

/**
 * The parts of a GitHub webhook request we use, extracted at the HTTP edge
 * (actions never see the Request).
 */
final readonly class WebhookRequest
{
    public function __construct(
        public string $rawBody,
        public ?string $signature,
        public ?string $deliveryId,
        public ?string $event,
        public ?string $targetType,
        public ?string $targetId,
    ) {}
}
