<?php

declare(strict_types=1);

namespace App\Support\Git;

use App\Enums\Git\GitProviderKind;
use App\Services\GitHub\WebhookSignature;
use SensitiveParameter;

/**
 * Webhook verification for git connections (docs/19 §5, docs/09 R17a), fail
 * closed: an empty secret or header never matches. GitLab sends the secret
 * itself (`X-Gitlab-Token`), Gitea/Forgejo an HMAC-SHA256 hex of the raw
 * body, the generic git webhook `sha256=<hex>`.
 */
final class ConnectionWebhookSignature
{
    public static function isValid(GitProviderKind $provider, string $rawBody, string $signature, #[SensitiveParameter] string $secret): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }

        return match ($provider) {
            GitProviderKind::GitLab => hash_equals($secret, $signature),
            GitProviderKind::Gitea => preg_match('/^[0-9a-fA-F]{64}$/D', $signature) === 1 && hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($signature)),
            GitProviderKind::Git => WebhookSignature::isValid($rawBody, $signature, $secret),
            GitProviderKind::GitHub => false,
        };
    }
}
