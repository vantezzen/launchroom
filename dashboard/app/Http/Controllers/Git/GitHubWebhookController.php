<?php

declare(strict_types=1);

namespace App\Http\Controllers\Git;

use App\Actions\Git\ReceiveGitHubWebhook;
use App\Data\Git\WebhookRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public GitHub webhook receiver (docs/09 R17). Authenticated by the
 * `X-Hub-Signature-256` HMAC of the app's webhook secret, not by a session.
 */
final class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request, ReceiveGitHubWebhook $receive): JsonResponse
    {
        // The raw bytes, exactly as signed: never the re-encoded parsed JSON.
        $body = $request->getContent();

        if (strlen($body) > config()->integer('launchroom.github.max_webhook_bytes')) {
            return new JsonResponse(['status' => 'too_large'], 413);
        }

        $receipt = $receive->handle(new WebhookRequest(
            rawBody: $body,
            signature: $request->header('X-Hub-Signature-256'),
            deliveryId: $request->header('X-GitHub-Delivery'),
            event: $request->header('X-GitHub-Event'),
            targetType: $request->header('X-GitHub-Hook-Installation-Target-Type'),
            targetId: $request->header('X-GitHub-Hook-Installation-Target-ID'),
        ));

        return new JsonResponse(['status' => $receipt->value], $receipt->httpStatus());
    }
}
