<?php

declare(strict_types=1);

namespace App\Enums\Previews;

use App\Enums\Git\GitProviderKind;

/**
 * Where a pull request comes from: GitHub, GitLab (merge requests) or
 * Gitea/Forgejo webhooks, or the local-dev simulation for plain git sources
 * (`launchroom:dev:pr`). Plain git servers have no pull requests.
 */
enum PreviewProvider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Gitea = 'gitea';
    case Git = 'git';

    public function gitProvider(): GitProviderKind
    {
        return match ($this) {
            self::GitHub => GitProviderKind::GitHub,
            self::GitLab => GitProviderKind::GitLab,
            self::Gitea => GitProviderKind::Gitea,
            self::Git => GitProviderKind::Git,
        };
    }

    public static function fromGitProvider(GitProviderKind $kind): self
    {
        return match ($kind) {
            GitProviderKind::GitHub => self::GitHub,
            GitProviderKind::GitLab => self::GitLab,
            GitProviderKind::Gitea => self::Gitea,
            GitProviderKind::Git => self::Git,
        };
    }
}
