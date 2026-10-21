<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Models\Git\GitConnection;
use App\Services\GitHub\JsonObject;

/**
 * Account details of a GitLab/Gitea connection (docs/19 §4.1).
 */
final class GitConnectionAccounts
{
    /**
     * Fills account fields from `GET /user` (GitLab: username; Gitea: login).
     */
    public static function apply(GitConnection $connection, JsonObject $account): void
    {
        $login = $account->stringOrNull('username') ?? $account->stringOrNull('login') ?? 'unknown';
        $connection->account_login = mb_substr($login, 0, 255);
        $connection->account_id = $account->intOrNull('id');
        $connection->name = mb_substr($connection->host().' · '.$login, 0, 100);
    }

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
