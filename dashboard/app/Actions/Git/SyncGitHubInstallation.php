<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Services\GitHub\Dto\InstallationInfo;

/**
 * Upserts an installation from data GitHub confirmed (a signed webhook or the
 * App JWT API). The team always comes from the app (docs/09 R18).
 */
final class SyncGitHubInstallation
{
    public function handle(GitHubApp $app, InstallationInfo $info): GitHubInstallation
    {
        $installation = GitHubInstallation::query()->updateOrCreate(
            ['github_app_id' => $app->id, 'installation_id' => $info->id],
            [
                'team_id' => $app->team_id,
                'account_id' => $info->accountId,
                'account_login' => $info->accountLogin,
                'account_type' => $info->accountType,
                'repository_selection' => $info->repositorySelection,
                'suspended_at' => $info->suspended ? now() : null,
                'removed_at' => null,
            ],
        );

        return $installation->setRelation('app', $app);
    }
}
