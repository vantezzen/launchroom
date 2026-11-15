<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\ConnectionWebhookRequest;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\WebhookDeliveryStatus;
use App\Enums\Git\WebhookReceipt;
use App\Exceptions\DecryptionFailed;
use App\Jobs\Git\PollConnectionNowJob;
use App\Jobs\Git\ProcessWebhookDeliveryJob;
use App\Models\Git\GitConnection;
use App\Models\Git\WebhookDelivery;
use App\Services\Crypto\SealedColumns;
use App\Services\Git\GitProviders;
use App\Support\Json\InvalidJsonPayload;
use App\Support\Json\JsonObject;
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
        private SealedColumns $sealed,
        private GitProviders $providers,
    ) {}

    public function handle(ConnectionWebhookRequest $request): WebhookReceipt
    {
        $connection = $this->verifiedConnection($request);

        if (! $connection instanceof GitConnection) {
            return WebhookReceipt::Unauthorized;
        }

        $connection->forceFill(['webhook_last_received_at' => now()])->saveQuietly();

        if (! $request->provider->hasApi()) {
            dispatch(new PollConnectionNowJob($connection->id));

            return WebhookReceipt::Accepted;
        }

        $provider = $this->providers->api($request->provider);
        $deliveryId = $request->deliveryId ?? '';
        $event = $provider->webhookEvent($request->event);

        if (preg_match('/^[A-Za-z0-9-]{1,56}$/D', $deliveryId) !== 1 || $event === null) {
            return WebhookReceipt::Invalid;
        }

        try {
            [$repositoryId, $action] = $provider->webhookSummary(JsonObject::decode($request->rawBody));
        } catch (InvalidJsonPayload) {
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

        dispatch(new ProcessWebhookDeliveryJob($delivery->id, $request->rawBody));

        return WebhookReceipt::Accepted;
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
            $secret = (string) $this->sealed->reveal($connection, 'webhook_secret');
        } catch (DecryptionFailed) {
            Log::error('A git connection webhook secret could not be decrypted', ['connection' => $connection->public_id]);

            return null;
        }

        return $this->providers->connection($request->provider)->verifyWebhook($request->rawBody, $request->signature, $secret) ? $connection : null;
    }
}
