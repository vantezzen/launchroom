<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Models\Git\GitConnection;

/**
 * Account lookups of GitLab/Gitea connections (docs/19 §4.1); the providers
 * read the account itself.
 */
final class GitConnectionAccounts
{
    /**
     * Another connection of the same team to the same account, if any (locked).
     */
    public static function existing(GitConnection $connection): ?GitConnection
    {
        return GitConnection::query()
            ->where('team_id', $connection->team_id)
            ->where('provider', $connection->provider->value)
            ->where('base_url', $connection->base_url)
            ->where('account_id', $connection->account_id)
            ->where('id', '!=', $connection->id ?? 0)
            ->lockForUpdate()
            ->first();
    }
}
