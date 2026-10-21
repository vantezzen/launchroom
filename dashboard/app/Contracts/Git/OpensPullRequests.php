<?php

declare(strict_types=1);

namespace App\Contracts\Git;

use App\Data\Git\FilePullRequest;
use App\Data\Git\RepositoryRef;
use RuntimeException;

/**
 * A git provider that can propose one new file as a pull/merge request
 * (docs/20 §5 "Commit to repository"): a branch from the default branch, one
 * commit adding the file, the request. Implemented by GitHub, GitLab and
 * Gitea/Forgejo; plain git servers can't.
 */
interface OpensPullRequests
{
    /**
     * @return string the pull/merge request's web URL
     *
     * @throws RuntimeException with a user-safe message
     */
    public function openFilePullRequest(RepositoryRef $repository, FilePullRequest $request): string;
}
