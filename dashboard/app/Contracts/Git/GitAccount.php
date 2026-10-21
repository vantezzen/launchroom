<?php

declare(strict_types=1);

namespace App\Contracts\Git;

use App\Enums\Git\GitProviderKind;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubInstallation;

/**
 * Whatever a push or pull request arrived through (docs/19 §2): a GitHub App
 * installation ({@see GitHubInstallation}) or a GitLab/Gitea/plain git
 * connection ({@see GitConnection}). Services match on
 * `(source.provider, source.installationId)` = (provider, accountId()).
 */
interface GitAccount
{
    public function gitProvider(): GitProviderKind;

    /** The row id stored as `GitSource::installationId`. */
    public function accountId(): int;

    public function accountTeamId(): int;
}
