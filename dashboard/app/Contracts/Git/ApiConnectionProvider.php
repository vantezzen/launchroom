<?php

declare(strict_types=1);

namespace App\Contracts\Git;

use App\Data\Git\PushPayload;
use App\Enums\Git\GitServerKind;
use App\Events\Git\PullRequestEventReceived;
use App\Exceptions\DomainRuleViolation;
use App\Models\Git\GitConnection;
use App\Support\Json\JsonObject;
use Illuminate\Http\Client\Response;

/**
 * A connection provider with an HTTP API (GitLab, Gitea/Forgejo; docs/19
 * §4.1, §5): its API root, OAuth endpoints, account shape and webhook
 * payloads.
 */
interface ApiConnectionProvider extends ConnectionProvider
{
    /** `<origin>/api/v4` (GitLab) or `<origin>/api/v1` (Gitea). */
    public function apiRoot(string $origin): string;

    /** The URL whose answer tells whether $origin is this kind of server. */
    public function probeUrl(string $origin): string;

    /**
     * Which server answered {@see probeUrl()}.
     *
     * @throws DomainRuleViolation when it isn't this kind of server
     */
    public function recognize(Response $response, string $origin): GitServerKind;

    public function oauthTokenUrl(GitConnection $connection): string;

    /**
     * @param  array<string, string>  $query
     */
    public function oauthAuthorizeUrl(GitConnection $connection, array $query): string;

    /** Fills account fields from the provider's `GET /user` answer. */
    public function applyAccount(GitConnection $connection, JsonObject $account): void;

    /**
     * The normalized event name of a delivery's event header, or null when
     * the header is malformed.
     */
    public function webhookEvent(?string $header): ?string;

    /**
     * The delivery metadata kept on the row: repository id and action.
     *
     * @return array{int|null, string|null}
     */
    public function webhookSummary(JsonObject $payload): array;

    /**
     * A verified delivery as a neutral push or pull request event (null:
     * nothing launchroom acts on). Repositories are matched within
     * $connection only (docs/09 R18).
     */
    public function parseWebhook(GitConnection $connection, string $event, JsonObject $payload): PushPayload|PullRequestEventReceived|null;
}
