<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Data\Git\RepositoryRef;
use App\Enums\Git\GitEventSource;
use App\Events\Git\GitPushReceived;
use App\Exceptions\GitHeadUnavailable;
use App\Models\Git\GitConnection;
use App\Models\Git\GitRepository;
use App\Services\Git\GitProviders;
use App\Support\Git\GitRef;
use App\Support\Git\SkipMarkers;

/**
 * Polling for one branch of a GitLab, Gitea or plain git repository
 * (docs/06 §2, docs/19 §1): asks the provider for the branch head; a changed
 * head emits the same {@see GitPushReceived} a webhook would. The first
 * observation of a ref only records a baseline.
 */
final readonly class PollConnectionRef
{
    public function __construct(
        private GitProviders $providers,
        private RecordConnectionRefHead $recordRefHead,
    ) {}

    /**
     * @return bool whether an event was emitted
     */
    public function handle(GitConnection $connection, int $repositoryId, string $ref): bool
    {
        if (! $connection->isActive() || ! GitRef::isValid($ref) || ! str_starts_with($ref, 'refs/heads/')) {
            return false;
        }

        $repository = GitRepository::query()->where('git_connection_id', $connection->id)->where('external_id', $repositoryId)->first();

        if (! $repository instanceof GitRepository) {
            return false;
        }

        try {
            $commit = $this->providers->for($connection->provider)->head(
                new RepositoryRef($connection->provider, $connection->team_id, $connection->id, $repositoryId, $repository->full_name, $connection->base_url),
                GitRef::shortName($ref),
            );
        } catch (GitHeadUnavailable) {
            return false;
        }

        $update = $this->recordRefHead->handle($connection, $repositoryId, $ref, $commit->sha, polled: true);

        if (! $update->changed || $update->previous === null || SkipMarkers::matches($commit->message)) {
            return false;
        }

        event(new GitPushReceived($connection, $repositoryId, $ref, $update->previous, $commit->sha, $commit, null, false, GitEventSource::Polling));

        return true;
    }
}
