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
 * App installation events keep the installation in sync; check events
 * nudge deployments waiting for that commit's checks (D111). Everything is
 * scoped to the delivery's app or connection, and through it to one team: a
 * repository of another team's account never matches (docs/09 R18).
 */
final readonly class ProcessWebhookDelivery
{
    /** GitHub events about a commit's checks (deploy after checks pass, D111). */
    private const array CHECK_EVENTS = ['check_run', 'check_suite', 'status'];

    public function __construct(
        private GitProviders $providers,
        private GitHubWebhooks $github,
        private ResolveInstallation $resolveInstallation,
        private HandleInstallationEvent $installationEvent,
        private ObservePush $observePush,
        private ReevaluateCommitChecks $checks,
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

        if (! in_array($delivery->event, ['push', 'pull_request', ...self::CHECK_EVENTS], true)) {
            return WebhookDeliveryStatus::Ignored;
        }

        $installationId = $payload->objectOrNull('installation')?->intOrNull('id');
        $installation = $installationId === null ? null : $this->resolveInstallation->handle($app, $installationId);

        if (! $installation instanceof GitHubInstallation) {
            return WebhookDeliveryStatus::Ignored;
        }

        if (in_array($delivery->event, self::CHECK_EVENTS, true)) {
            return $this->checksChanged($installation, $delivery->event, $payload);
        }

        return $this->emit($installation, $this->github->parse($installation, $delivery->event, $payload));
    }

    /**
     * A check of a commit changed: deployments waiting for that commit's
     * checks read them again (D111). The commit is `check_run.head_sha`,
     * `check_suite.head_sha` or the status's `sha`.
     */
    private function checksChanged(GitHubInstallation $installation, string $event, JsonObject $payload): WebhookDeliveryStatus
    {
        $repositoryId = $payload->objectOrNull('repository')?->intOrNull('id');
        $sha = match ($event) {
            'check_run' => $payload->objectOrNull('check_run')?->stringOrNull('head_sha'),
            'check_suite' => $payload->objectOrNull('check_suite')?->stringOrNull('head_sha'),
            default => $payload->stringOrNull('sha'),
        };

        return $repositoryId !== null && $sha !== null && $this->checks->handle($installation, $repositoryId, $sha)
            ? WebhookDeliveryStatus::Processed
            : WebhookDeliveryStatus::Ignored;
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
