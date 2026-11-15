<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\PushPayload;
use App\Enums\Git\WebhookDeliveryStatus;
use App\Events\Git\GitPushReceived;
use App\Events\Git\PullRequestEventReceived;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\WebhookDelivery;
use App\Services\Git\GitProviders;
use App\Services\Git\Providers\GitHub\GitHubWebhooks;
use App\Support\Json\JsonObject;

/**
 * Turns a verified, deduplicated delivery (a GitHub App's, or a GitLab or
 * Gitea/Forgejo connection's; docs/19 §5), parsed by its provider, into the
 * neutral {@see GitPushReceived} / {@see PullRequestEventReceived}. GitHub
 * App installation events keep the installation in sync. Everything is
 * scoped to the delivery's app or connection, and through it to one team: a
 * repository of another team's account never matches (docs/09 R18).
 */
final readonly class ProcessWebhookDelivery
{
    public function __construct(
        private GitProviders $providers,
        private GitHubWebhooks $github,
        private ResolveInstallation $resolveInstallation,
        private HandleInstallationEvent $installationEvent,
        private ObservePush $observePush,
    ) {}

    public function handle(WebhookDelivery $delivery, string $rawBody): WebhookDeliveryStatus
    {
        $payload = JsonObject::decode($rawBody);
        $app = $delivery->app;
        $connection = $delivery->gitConnection;

        if ($connection instanceof GitConnection) {
            if (! $connection->provider->hasApi()) {
                return WebhookDeliveryStatus::Ignored;
            }

            return $this->emit($connection, $this->providers->api($connection->provider)->parseWebhook($connection, $delivery->event, $payload));
        }

        if (! $app instanceof GitHubApp) {
            return WebhookDeliveryStatus::Ignored;
        }

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

        return $this->emit($installation, $this->github->parse($installation, $delivery->event, $payload));
    }

    private function emit(GitHubInstallation|GitConnection $account, PushPayload|PullRequestEventReceived|null $parsed): WebhookDeliveryStatus
    {
        if ($parsed instanceof PullRequestEventReceived) {
            event($parsed);

            return WebhookDeliveryStatus::Processed;
        }

        return $parsed instanceof PushPayload && $this->observePush->handle($account, $parsed) ? WebhookDeliveryStatus::Processed : WebhookDeliveryStatus::Ignored;
    }
}
