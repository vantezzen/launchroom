<?php

declare(strict_types=1);

namespace App\Data\Git;

use App\Enums\Git\GitProviderKind;

/**
 * The parts of a GitLab, Gitea/Forgejo or generic git webhook request we use
 * (docs/19 §5), extracted at the HTTP edge.
 */
final readonly class ConnectionWebhookRequest
{
    public function __construct(
        public GitProviderKind $provider,
        public string $connection,
        public string $rawBody,
        /** GitLab: `X-Gitlab-Token`; Gitea: the HMAC hex; git: `sha256=<hex>`. */
        public ?string $signature,
        public ?string $deliveryId,
        public ?string $event,
    ) {}
}
