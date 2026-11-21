<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Contracts\Git\GitAccount;
use App\Jobs\Deployments\EvaluateDeploymentChecksJob;
use App\Models\Deployment;
use App\Support\Git\GitRef;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * A check of a commit changed (GitHub `check_run`, `check_suite`, `status`
 * webhooks; D111): every push deployment of that account's repository and
 * commit that waits for its checks reads them again. The payload is only a
 * nudge; the evaluation reads the checks from the API. Scoped to the
 * account's team through Service::sourcedFrom (docs/09 R18).
 *
 * Returns whether any deployment waited for this commit.
 */
final readonly class ReevaluateCommitChecks
{
    public function handle(GitAccount $account, int $repositoryId, string $sha): bool
    {
        if (! GitRef::isSha($sha)) {
            return false;
        }

        /** @var list<int> $ids */
        $ids = Deployment::query()
            ->where('team_id', $account->accountTeamId())
            ->where('commit_sha', $sha)
            ->waitingForChecks()
            ->whereHas('serviceInstance.service', static fn (Builder $query) => $query->sourcedFrom($account, $repositoryId))
            ->pluck('id')
            ->all();

        foreach ($ids as $id) {
            dispatch(new EvaluateDeploymentChecksJob($id));
        }

        return $ids !== [];
    }
}
