<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\ConnectionWebhookRequest;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitProviderKind;
use App\Enums\Git\WebhookDeliveryStatus;
use App\Enums\Git\WebhookReceipt;
use App\Jobs\Git\PollConnectionNowJob;
use App\Jobs\Git\ProcessConnectionWebhookJob;
use App\Models\Git\GitConnection;
use App\Models\Git\WebhookDelivery;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use App\Services\Git\GitConnectionSecrets;
use App\Services\GitHub\Exceptions\InvalidGitHubPayload;
use App\Services\GitHub\JsonObject;
use App\Support\Git\ConnectionWebhookSignature;
use App\Support\PublicId;
use Illuminate\Support\Facades\Log;

/**
 * `POST /webhooks/{gitlab|gitea|git}/{gic_…}` (docs/19 §5, docs/09 R17):
 *
 * 1. find the connection by its public id and provider, verify the request
 *    with its secret (fail closed: GitLab's `X-Gitlab-Token` compared with
 *    hash_equals, Gitea/Forgejo's HMAC-SHA256 of the raw body, generic
 *    `sha256=` HMAC);
 * 2. dedupe on the delivery id (GitLab/Gitea);
 * 3. store minimal metadata and queue processing with the payload in an
 *    encrypted job (generic: poll the repository now).
 */
final readonly class ReceiveConnectionWebhook
{
    public function __construct(
        private GitConnectionSecrets $secrets,
    ) {}

    public function handle(ConnectionWebhookRequest $request): WebhookReceipt
    {
        $connection = $this->verifiedConnection($request);

        if (! $connection instanceof GitConnection) {
            return WebhookReceipt::Unauthorized;
        }

        $connection->forceFill(['webhook_last_received_at' => now()])->saveQuietly();

        if ($request->provider === GitProviderKind::Git) {
            dispatch(new PollConnectionNowJob($connection->id));

            return WebhookReceipt::Accepted;
        }

        $deliveryId = $request->deliveryId ?? '';
        $event = $this->event($request);

        if (preg_match('/^[A-Za-z0-9-]{1,56}$/D', $deliveryId) !== 1 || $event === null) {
            return WebhookReceipt::Invalid;
        }

        try {
            $payload = JsonObject::decode($request->rawBody);
            $repositoryId = $payload->objectOrNull($request->provider === GitProviderKind::GitLab ? 'project' : 'repository')?->intOrNull('id');
            $action = $request->provider === GitProviderKind::GitLab
                ? $payload->objectOrNull('object_attributes')?->stringOrNull('action')
                : $payload->stringOrNull('action');
        } catch (InvalidGitHubPayload) {
            return WebhookReceipt::Invalid;
        }

        $inserted = WebhookDelivery::query()->insertOrIgnore([
            'delivery_id' => $request->provider->value.':'.$deliveryId,
            'git_connection_id' => $connection->id,
            'team_id' => $connection->team_id,
            'event' => $event,
            'action' => $action === null ? null : mb_substr($action, 0, 64),
            'repository_id' => $repositoryId,
            'received_at' => now(),
        ]);

        if ($inserted === 0) {
            return WebhookReceipt::Duplicate;
        }

        $delivery = WebhookDelivery::query()->where('delivery_id', $request->provider->value.':'.$deliveryId)->sole();

        if (! in_array($event, ['push', 'tag_push', 'merge_request', 'pull_request'], true)) {
            $delivery->transitionTo(WebhookDeliveryStatus::Ignored);

            return WebhookReceipt::Accepted;
        }

        dispatch(new ProcessConnectionWebhookJob($delivery->id, $request->rawBody));

        return WebhookReceipt::Accepted;
    }

    /**
     * Normalized event name, or null when the header is malformed.
     */
    private function event(ConnectionWebhookRequest $request): ?string
    {
        $event = $request->event ?? '';

        if ($request->provider === GitProviderKind::GitLab) {
            return match ($event) {
                'Push Hook' => 'push',
                'Tag Push Hook' => 'tag_push',
                'Merge Request Hook' => 'merge_request',
                default => preg_match('/^[A-Za-z ]{1,64}$/D', $event) === 1 ? strtolower(str_replace(' ', '_', $event)) : null,
            };
        }

        if (preg_match('/^[a-z_]{1,64}$/D', $event) !== 1) {
            return null;
        }

        // Gitea sends pull_request_sync / pull_request_label as their own events.
        return str_starts_with($event, 'pull_request') ? 'pull_request' : $event;
    }

    private function verifiedConnection(ConnectionWebhookRequest $request): ?GitConnection
    {
        if ($request->signature === null || $request->signature === '' || ! PublicId::isValid($request->connection, GitConnection::publicIdPrefix())) {
            return null;
        }

        $connection = GitConnection::query()
            ->where('public_id', $request->connection)
            ->where('provider', $request->provider->value)
            ->whereIn('status', [GitConnectionStatus::Active->value, GitConnectionStatus::NeedsReauth->value])
            ->first();

        if (! $connection instanceof GitConnection) {
            return null;
        }

        try {
            $secret = $this->secrets->webhookSecret($connection);
        } catch (DecryptionFailed) {
            Log::error('A git connection webhook secret could not be decrypted', ['connection' => $connection->public_id]);

            return null;
        }

        return ConnectionWebhookSignature::isValid($request->provider, $request->rawBody, $request->signature, $secret) ? $connection : null;
    }
}
