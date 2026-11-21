<?php

declare(strict_types=1);

namespace App\Services\Git\Providers\GitHub;

use App\Contracts\Git\ReadsCommitChecks;
use App\Data\Git\CommitCheckData;
use App\Enums\Git\CommitCheckState;
use App\Exceptions\CommitChecksUnavailable;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRepository;
use App\Support\Json\JsonObject;
use Throwable;

/**
 * The CI checks of a commit on GitHub (D111, docs/06 §2.5): its check runs
 * (the latest per name) and its commit statuses (the latest per context),
 * without this App's own check runs and launchroom's statuses. Check suites
 * are not read: GitHub opens one for every app with checks access on every
 * push, and apps that never run anything leave theirs queued.
 */
final readonly class GitHubCommitChecks
{
    /** 100 per page: more checks than this on one commit are not expected. */
    private const int MAX_PAGES = 3;

    private const array PASSING_CONCLUSIONS = ['success', 'neutral', 'skipped'];

    public function __construct(
        private GitHubClientFactory $clients,
    ) {}

    /**
     * @return list<CommitCheckData>
     *
     * @throws CommitChecksUnavailable
     */
    public function read(GitHubRepository $repository, GitHubInstallation $installation, string $sha): array
    {
        $client = $this->clients->for($installation->app);
        $scope = TokenScope::checks($repository->github_repo_id);
        $base = "/repos/{$repository->full_name}/commits/{$sha}";
        $checks = [];

        try {
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $json = JsonObject::decode($client->asInstallation($installation->installation_id, $scope, 'GET', "{$base}/check-runs", query: ['filter' => 'latest', 'per_page' => 100, 'page' => $page])->body());
                $runs = $json->objects('check_runs');

                foreach ($runs as $run) {
                    if ($run->objectOrNull('app')?->intOrNull('id') === $installation->app->app_id) {
                        continue;
                    }

                    $checks[] = $this->checkRun($run);
                }

                if (count($runs) < 100) {
                    break;
                }
            }

            $status = JsonObject::decode($client->asInstallation($installation->installation_id, $scope, 'GET', "{$base}/status", query: ['per_page' => 100])->body());

            foreach ($status->objects('statuses') as $entry) {
                $context = $entry->stringOrNull('context') ?? '';

                if ($context === '' || str_starts_with($context, ReadsCommitChecks::OWN_PREFIX)) {
                    continue;
                }

                $checks[] = new CommitCheckData(mb_substr($context, 0, 255), match ($entry->stringOrNull('state')) {
                    'success' => CommitCheckState::Passed,
                    'failure', 'error' => CommitCheckState::Failed,
                    default => CommitCheckState::Pending,
                }, CommitCheckData::link($entry->stringOrNull('target_url')));
            }
        } catch (Throwable) {
            throw new CommitChecksUnavailable('GitHub didn’t answer with the checks of this commit.');
        }

        return $checks;
    }

    private function checkRun(JsonObject $run): CommitCheckData
    {
        $state = $run->stringOrNull('status') !== 'completed'
            ? CommitCheckState::Pending
            : (in_array($run->stringOrNull('conclusion'), self::PASSING_CONCLUSIONS, true) ? CommitCheckState::Passed : CommitCheckState::Failed);

        return new CommitCheckData(mb_substr($run->stringOrNull('name') ?? 'Check', 0, 255), $state, CommitCheckData::link($run->stringOrNull('html_url') ?? $run->stringOrNull('details_url')));
    }
}
