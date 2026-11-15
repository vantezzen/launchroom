<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\CommitData;
use App\Data\Git\RepositoryRef;
use App\Enums\Git\GitEventSource;
use App\Events\Git\GitPushReceived;
use App\Exceptions\GitHeadUnavailable;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRefHead;
use App\Models\Git\GitRefHead;
use App\Services\Git\GitProviders;
use App\Support\Git\GitRef;

/**
 * Polling for one tracked branch, for every provider (docs/06 §2, docs/19
 * §1): asks the provider for the branch head, conditionally where it can
 * (GitHub's ETag); a changed head emits the same {@see GitPushReceived} a
 * webhook would. The first observation of a ref only records a baseline.
 */
final readonly class PollTrackedRef
{
    public function __construct(
        private GitProviders $providers,
        private ObserveRefHead $observe,
    ) {}

    /**
     * @param  bool  $webhook  a webhook asked for this poll (docs/19 §4.2)
     * @return bool whether an event was emitted
     */
    public function handle(GitHubInstallation|GitConnection $account, int $repositoryId, string $ref, bool $webhook = false): bool
    {
        if (! $account->isActive() || ! GitRef::isValid($ref) || ! str_starts_with($ref, 'refs/heads/')) {
            return false;
        }

        $name = $account->repositoryName($repositoryId);

        if ($name === null) {
            return false;
        }

        $head = $this->head($account, $repositoryId, $ref);

        try {
            $polled = $this->providers->for($account->gitProvider())->pollHead(
                new RepositoryRef($account->gitProvider(), $account->accountTeamId(), $account->accountId(), $repositoryId, $name, $account instanceof GitConnection ? $account->base_url : null),
                GitRef::shortName($ref),
                $head instanceof GitHubRefHead ? $head->etag : null,
            );
        } catch (GitHeadUnavailable) {
            return false;
        }

        if (! $polled->commit instanceof CommitData) {
            $head?->forceFill(['last_polled_at' => now()])->save();

            return false;
        }

        return $this->observe->handle($account, $repositoryId, $ref, $polled->commit, GitEventSource::Polling, etag: $polled->etag, webhookAsked: $webhook);
    }

    private function head(GitHubInstallation|GitConnection $account, int $repositoryId, string $ref): GitHubRefHead|GitRefHead|null
    {
        return $account instanceof GitHubInstallation
            ? GitHubRefHead::query()->where(['github_installation_id' => $account->id, 'github_repo_id' => $repositoryId, 'ref' => $ref])->first()
            : GitRefHead::query()->where(['git_connection_id' => $account->id, 'external_id' => $repositoryId, 'ref' => $ref])->first();
    }
}
