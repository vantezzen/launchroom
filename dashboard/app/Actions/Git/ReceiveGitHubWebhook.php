<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\WebhookRequest;
use App\Enums\Git\WebhookDeliveryStatus;
use App\Enums\Git\WebhookReceipt;
use App\Jobs\Git\ProcessGitHubWebhookJob;
use App\Models\Git\GitHubApp;
use App\Models\Git\WebhookDelivery;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use App\Services\GitHub\Exceptions\InvalidGitHubPayload;
use App\Services\GitHub\GitHubAppSecrets;
use App\Services\GitHub\JsonObject;
use App\Services\GitHub\WebhookSignature;
use Illuminate\Support\Facades\Log;

/**
 * `POST /webhooks/github` (docs/09 R17):
 *
 * 1. find the app by `X-GitHub-Hook-Installation-Target-ID` and verify
 *    `X-Hub-Signature-256` over the raw body with its secret (fail closed);
 * 2. dedupe on `X-GitHub-Delivery` (unique row; a replay is not processed);
 * 3. store minimal metadata and queue processing with the payload in an
 *    encrypted job. GitHub gets 202 right away.
 */
final readonly class ReceiveGitHubWebhook
{
    public function __construct(
        private GitHubAppSecrets $secrets,
    ) {}

    public function handle(WebhookRequest $request): WebhookReceipt
    {
        $app = $this->verifiedApp($request);

        if (! $app instanceof GitHubApp) {
            return WebhookReceipt::Unauthorized;
        }

        $deliveryId = $request->deliveryId ?? '';
        $event = $request->event ?? '';

        if (preg_match('/^[A-Za-z0-9-]{1,64}$/D', $deliveryId) !== 1 || preg_match('/^[a-z_]{1,64}$/D', $event) !== 1) {
            return WebhookReceipt::Invalid;
        }

        try {
            $payload = JsonObject::decode($request->rawBody);
            $action = $payload->stringOrNull('action');
            $installationId = $payload->objectOrNull('installation')?->intOrNull('id');
            $repositoryId = $payload->objectOrNull('repository')?->intOrNull('id');
        } catch (InvalidGitHubPayload) {
            return WebhookReceipt::Invalid;
        }

        $inserted = WebhookDelivery::query()->insertOrIgnore([
            'delivery_id' => $deliveryId,
            'github_app_id' => $app->id,
            'team_id' => $app->team_id,
            'event' => $event,
            'action' => $action === null ? null : mb_substr($action, 0, 64),
            'installation_id' => $installationId,
            'repository_id' => $repositoryId,
            'received_at' => now(),
        ]);

        $app->forceFill(['webhook_last_received_at' => now()])->saveQuietly();

        if ($inserted === 0) {
            return WebhookReceipt::Duplicate;
        }

        $delivery = WebhookDelivery::query()->where('delivery_id', $deliveryId)->sole();

        if ($event === 'ping') {
            $delivery->transitionTo(WebhookDeliveryStatus::Ignored);

            return WebhookReceipt::Accepted;
        }

        dispatch(new ProcessGitHubWebhookJob($delivery->id, $request->rawBody));

        return WebhookReceipt::Accepted;
    }

    private function verifiedApp(WebhookRequest $request): ?GitHubApp
    {
        if ($request->signature === null || $request->targetType !== 'integration'
            || $request->targetId === null || preg_match('/^[1-9][0-9]{0,18}$/D', $request->targetId) !== 1) {
            return null;
        }

        // Normally one row; the same numeric id can exist on github.com and a
        // GitHub Enterprise Server, so try each app's secret.
        $candidates = GitHubApp::query()->where('app_id', (int) $request->targetId)->get();

        foreach ($candidates as $app) {
            try {
                $secret = $this->secrets->webhookSecret($app);
            } catch (DecryptionFailed) {
                Log::error('GitHub webhook secret could not be decrypted', ['github_app' => $app->public_id]);

                continue;
            }

            if (WebhookSignature::isValid($request->rawBody, $request->signature, $secret)) {
                return $app;
            }
        }

        return null;
    }
}
