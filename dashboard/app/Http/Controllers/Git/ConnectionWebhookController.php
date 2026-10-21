<?php

declare(strict_types=1);

namespace App\Http\Controllers\Git;

use App\Actions\Git\ReceiveConnectionWebhook;
use App\Data\Git\ConnectionWebhookRequest;
use App\Enums\Git\GitProviderKind;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public webhook receivers for GitLab, Gitea/Forgejo and generic git
 * (docs/19 §5). Authenticated by the connection's secret (fail closed), not
 * by a session.
 */
final class ConnectionWebhookController extends Controller
{
    public function gitlab(Request $request, string $connection, ReceiveConnectionWebhook $receive): JsonResponse
    {
        return $this->receive($receive, new ConnectionWebhookRequest(
            GitProviderKind::GitLab,
            $connection,
            $request->getContent(),
            $request->header('X-Gitlab-Token'),
            $request->header('X-Gitlab-Event-UUID'),
            $request->header('X-Gitlab-Event'),
        ));
    }

    public function gitea(Request $request, string $connection, ReceiveConnectionWebhook $receive): JsonResponse
    {
        return $this->receive($receive, new ConnectionWebhookRequest(
            GitProviderKind::Gitea,
            $connection,
            $request->getContent(),
            $request->header('X-Forgejo-Signature') ?? $request->header('X-Gitea-Signature'),
            $request->header('X-Forgejo-Delivery') ?? $request->header('X-Gitea-Delivery'),
            $request->header('X-Forgejo-Event') ?? $request->header('X-Gitea-Event'),
        ));
    }

    public function git(Request $request, string $connection, ReceiveConnectionWebhook $receive): JsonResponse
    {
        return $this->receive($receive, new ConnectionWebhookRequest(
            GitProviderKind::Git,
            $connection,
            $request->getContent(),
            $request->header('X-Launchroom-Signature') ?? $request->header('X-Hub-Signature-256'),
            null,
            null,
        ));
    }

    private function receive(ReceiveConnectionWebhook $receive, ConnectionWebhookRequest $request): JsonResponse
    {
        if (strlen($request->rawBody) > config()->integer('launchroom.github.max_webhook_bytes')) {
            return new JsonResponse(['status' => 'too_large'], 413);
        }

        $receipt = $receive->handle($request);

        return new JsonResponse(['status' => $receipt->value], $receipt->httpStatus());
    }
}
