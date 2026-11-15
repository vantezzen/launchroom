<?php

declare(strict_types=1);

namespace App\Contracts\Git;

use App\Models\Git\GitConnection;
use App\Models\Git\GitRepository;
use RuntimeException;
use SensitiveParameter;

/**
 * A provider whose accounts are `git_connections` rows (docs/19 §4): GitLab,
 * Gitea/Forgejo and plain git servers. GitHub's App has its own tables and
 * webhook endpoint.
 */
interface ConnectionProvider extends GitProvider
{
    /** Where the provider delivers this connection's webhooks. */
    public function webhookUrl(GitConnection $connection): string;

    /**
     * Fail closed (docs/09 R17a): an empty secret or signature never matches.
     */
    public function verifyWebhook(string $rawBody, string $signature, #[SensitiveParameter] string $secret): bool;

    /**
     * Registers launchroom's webhook on the repository once (no-op where the
     * provider has no hook API). Never throws: the error stays on the row.
     */
    public function ensureWebhook(GitRepository $repository, #[SensitiveParameter] string $secret): void;

    /** Removes the webhook launchroom registered (best effort). */
    public function removeWebhook(GitRepository $repository): void;

    /**
     * Repositories of one connection matching $search.
     *
     * @return list<GitRepository>
     *
     * @throws RuntimeException
     */
    public function searchConnection(GitConnection $connection, string $search): array;
}
