<?php

declare(strict_types=1);

namespace App\Enums\Git;

/**
 * What runs behind a connection, for its logo and copy: Forgejo speaks the
 * Gitea API but deserves its own name (docs/19 §4.1).
 */
enum GitServerKind: string
{
    case GitLab = 'gitlab';
    case Gitea = 'gitea';
    case Forgejo = 'forgejo';
    case Git = 'git';

    public function label(): string
    {
        return match ($this) {
            self::GitLab => 'GitLab',
            self::Gitea => 'Gitea',
            self::Forgejo => 'Forgejo',
            self::Git => 'Git server',
        };
    }

    public function provider(): GitProviderKind
    {
        return match ($this) {
            self::GitLab => GitProviderKind::GitLab,
            self::Gitea, self::Forgejo => GitProviderKind::Gitea,
            self::Git => GitProviderKind::Git,
        };
    }
}
