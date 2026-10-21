<?php

declare(strict_types=1);

namespace App\Events\Git;

use App\Models\Git\GitConnection;
use App\Models\Git\GitHubInstallation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A pull request changed on GitHub (verified webhook). Fork detection uses
 * repository ids, never `author_association` (docs/09 R20);
 * a PR from a deleted fork has no head repository and counts as a fork.
 * Fork PRs get no previews unless a maintainer approves (docs/09 R20):
 * `authorPermission` (admin/maintain/write/triage/read/none) is looked up for
 * fork PRs only (null: not looked up for this event; `unknown`: the lookup
 * failed, which counts as no write access and replaces an earlier value). On `labeled` events of fork PRs,
 * `label` is the applied label and `senderPermission` the permission of the
 * user who applied it (approval by label needs write access).
 */
final class PullRequestEventReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  int  $repositoryId  numeric id of the base repository
     * @param  list<string>  $labels
     */
    public function __construct(
        /** The account it arrived through (GitHub installation or git connection, docs/19 §2). */
        public GitHubInstallation|GitConnection $installation,
        public int $repositoryId,
        public string $action,
        public int $number,
        public string $title,
        public string $headSha,
        public string $headRef,
        public string $baseRef,
        public ?int $headRepoId,
        public int $baseRepoId,
        public bool $isFork,
        public string $authorLogin,
        public ?string $authorPermission,
        public bool $draft,
        public bool $merged,
        public array $labels,
        public ?string $label = null,
        public ?string $senderLogin = null,
        public ?string $senderPermission = null,
    ) {}
}
