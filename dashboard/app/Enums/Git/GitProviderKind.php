<?php

declare(strict_types=1);

namespace App\Enums\Git;

/**
 * Where a repository lives (docs/19 §1): the value of `GitSource::provider`
 * and of the build task's `repo.provider`.
 */
enum GitProviderKind: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Gitea = 'gitea';
    case Git = 'git';

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Gitea => 'Gitea',
            self::Git => 'Git',
        };
    }

    /**
     * Providers whose connections live in `git_connections` (GitHub has its
     * App tables).
     */
    public function usesConnections(): bool
    {
        return $this !== self::GitHub;
    }

    /**
     * Providers with an HTTP API (repository list, files, statuses).
     */
    public function hasApi(): bool
    {
        return $this !== self::Git;
    }
}
