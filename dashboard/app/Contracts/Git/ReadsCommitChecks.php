<?php

declare(strict_types=1);

namespace App\Contracts\Git;

use App\Data\Git\CommitCheckData;
use App\Data\Git\RepositoryRef;
use App\Exceptions\CommitChecksUnavailable;

/**
 * A git provider that can list the CI checks of a commit (D111, docs/06
 * §2.5): GitHub (check runs and commit statuses), GitLab (commit statuses,
 * pipeline jobs included) and Gitea/Forgejo (commit statuses). launchroom's
 * own reports are left out. Plain git servers have none.
 */
interface ReadsCommitChecks
{
    /** Contexts and check names of launchroom's own reports start with this. */
    public const string OWN_PREFIX = 'launchroom';

    /**
     * The latest check per name, launchroom's own reports left out.
     *
     * @return list<CommitCheckData>
     *
     * @throws CommitChecksUnavailable
     */
    public function commitChecks(RepositoryRef $repository, string $sha): array;
}
