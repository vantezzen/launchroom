<?php

declare(strict_types=1);

namespace App\Listeners\Previews;

use App\Actions\Previews\SyncPullRequest;
use App\Data\Git\RepositoryRef;
use App\Data\Previews\PullRequestData;
use App\Events\Git\PullRequestEventReceived;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubRepository;
use App\Models\Git\GitRepository;
use App\Models\Project;
use App\Models\Service;
use App\Services\Git\GitProviders;

/**
 * Pull/merge request events → previews (docs/06 §5, docs/19 §5): every
 * project with a service sourced from this provider, installation or
 * connection and numeric repository id (docs/09 R18) gets its preview
 * synced. The PR's changed files are listed once (focused previews) for
 * events that carry new code.
 */
final readonly class SyncPreviewsOnPullRequest
{
    public function __construct(
        private SyncPullRequest $sync,
        private GitProviders $providers,
    ) {}

    public function handle(PullRequestEventReceived $event): void
    {
        $account = $event->installation;
        $provider = $account->gitProvider();

        /** @var list<int> $projectIds */
        $projectIds = Service::query()
            ->where('team_id', $account->team_id)
            ->whereRaw("source->>'type' = 'git'")
            ->whereRaw("COALESCE(source->>'provider', 'github') = ?", [$provider->value])
            ->whereRaw("(source->>'installationId')::bigint = ?", [$account->id])
            ->whereRaw("(source->>'repositoryId')::bigint = ?", [$event->repositoryId])
            ->distinct()
            ->pluck('project_id')
            ->all();

        if ($projectIds === []) {
            return;
        }

        $name = $account instanceof GitConnection
            ? GitRepository::query()->where('git_connection_id', $account->id)->where('external_id', $event->repositoryId)->value('full_name')
            : GitHubRepository::query()->where('github_installation_id', $account->id)->where('github_repo_id', $event->repositoryId)->value('full_name');
        $name = is_string($name) ? $name : (string) $event->repositoryId;

        $files = in_array($event->action, ['opened', 'reopened', 'synchronize'], true)
            ? $this->providers->for($provider)->changedFiles(new RepositoryRef($provider, $account->team_id, $account->id, $event->repositoryId, $name), $event->number, $event->baseRef, $event->headSha)
            : null;
        $pr = PullRequestData::fromEvent($event, $name, $files);

        foreach (Project::query()->whereIn('id', $projectIds)->where('team_id', $account->team_id)->orderBy('id')->get() as $project) {
            $this->sync->handle($project, $pr);
        }
    }
}
