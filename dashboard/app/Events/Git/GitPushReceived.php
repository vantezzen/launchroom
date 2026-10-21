<?php

declare(strict_types=1);

namespace App\Events\Git;

use App\Data\Git\CommitData;
use App\Enums\Git\GitEventSource;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubInstallation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A ref moved on GitHub, GitLab, Gitea/Forgejo or a plain git server
 * (verified webhook or polling fallback), already filtered: not a deletion,
 * no skip marker in the head commit, and not seen before through the other
 * channel. The core domain matches services by provider +
 * `$installation->accountId()` + numeric `$repositoryId` (docs/09 R18) and
 * environment triggers by `$ref`.
 */
final class GitPushReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  int  $repositoryId  the provider's numeric repository id (0 for plain git)
     * @param  string  $ref  full ref, `refs/heads/…` or `refs/tags/…`
     * @param  list<string>|null  $changedFiles  null when unknown (polling, or > 20 commits in the push)
     */
    public function __construct(
        /** The account it arrived through (GitHub installation or git connection, docs/19 §2). */
        public GitHubInstallation|GitConnection $installation,
        public int $repositoryId,
        public string $ref,
        public string $before,
        public string $after,
        public CommitData $headCommit,
        public ?array $changedFiles,
        public bool $forced,
        public GitEventSource $source,
    ) {}
}
