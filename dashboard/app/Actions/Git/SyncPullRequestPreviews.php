<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Actions\Previews\SyncPullRequest;
use App\Data\Git\RepositoryRef;
use App\Data\Previews\PullRequestData;
use App\Events\Git\PullRequestEventReceived;
use App\Models\Project;
use App\Models\Service;
use App\Services\Git\GitProviders;

/**
 * Pull/merge request events → previews (docs/06 §5, docs/19 §5): every
 * project with a service sourced from the event's repository
 * ({@see Service::sourcedFrom()}) gets its preview synced. The PR's changed
 * files are listed once (focused previews) for events that carry new code.
 */
final readonly class SyncPullRequestPreviews
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
            ->sourcedFrom($account, $event->repositoryId)
            ->distinct()
            ->pluck('project_id')
            ->all();

        if ($projectIds === []) {
            return;
        }

        $name = $account->repositoryName($event->repositoryId) ?? (string) $event->repositoryId;

        $files = $event->action->isCodeEvent()
            ? $this->providers->for($provider)->changedFiles(new RepositoryRef($provider, $account->accountTeamId(), $account->accountId(), $event->repositoryId, $name), $event->number, $event->baseRef, $event->headSha)
            : null;
        $pr = PullRequestData::fromEvent($event, $name, $files);

        foreach (Project::query()->whereIn('id', $projectIds)->where('team_id', $account->accountTeamId())->orderBy('id')->get() as $project) {
            $this->sync->handle($project, $pr);
        }
    }
}
