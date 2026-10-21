<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Enums\Git\WebhookDeliveryStatus;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\WebhookDelivery;
use App\Services\GitHub\JsonObject;

/**
 * Routes a verified, deduplicated delivery to its handler. Everything is
 * scoped to the delivery's app, and through it to one team: a repository of
 * another team's installation never matches (docs/09 R18).
 */
final readonly class ProcessGitHubWebhook
{
    public function __construct(
        private ResolveInstallation $resolveInstallation,
        private HandleInstallationEvent $installationEvent,
        private HandlePushEvent $pushEvent,
        private HandlePullRequestEvent $pullRequestEvent,
    ) {}

    public function handle(WebhookDelivery $delivery, string $rawBody): WebhookDeliveryStatus
    {
        $app = $delivery->app;

        if (! $app instanceof GitHubApp) {
            return WebhookDeliveryStatus::Ignored;
        }

        $payload = JsonObject::decode($rawBody);

        if (in_array($delivery->event, ['installation', 'installation_repositories'], true)) {
            return $this->installationEvent->handle($app, $delivery->event, $delivery->action, $payload);
        }

        if (! in_array($delivery->event, ['push', 'pull_request'], true)) {
            return WebhookDeliveryStatus::Ignored;
        }

        $installationId = $payload->objectOrNull('installation')?->intOrNull('id');
        $installation = $installationId === null ? null : $this->resolveInstallation->handle($app, $installationId);

        if (! $installation instanceof GitHubInstallation) {
            return WebhookDeliveryStatus::Ignored;
        }

        $emitted = $delivery->event === 'push'
            ? $this->pushEvent->handle($installation, $payload)
            : $this->pullRequestEvent->handle($installation, $payload);

        return $emitted ? WebhookDeliveryStatus::Processed : WebhookDeliveryStatus::Ignored;
    }
}
