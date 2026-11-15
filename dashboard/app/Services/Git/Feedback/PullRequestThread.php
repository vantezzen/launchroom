<?php

declare(strict_types=1);

namespace App\Services\Git\Feedback;

use App\Models\Git\GitHubPullRequestComment;
use App\Models\Git\GitPullRequestComment;
use Closure;

/**
 * One pull/merge request's launchroom comment, as a provider reaches it:
 * the stored row and the provider's create/update calls. GitHub keeps its
 * rows in `github_pull_request_comments` (keyed by installation), the
 * connection providers in `git_pull_request_comments` (keyed by connection).
 */
final readonly class PullRequestThread
{
    /**
     * @param  Closure(bool): (GitPullRequestComment|GitHubPullRequestComment|null)  $comment  the stored row (created when the argument is true)
     * @param  Closure(int, string): bool  $update  edits the comment; false when the provider no longer has it
     * @param  Closure(string): int  $create  posts a new comment and returns its id
     */
    public function __construct(
        public string $lockKey,
        private Closure $comment,
        private Closure $update,
        private Closure $create,
    ) {}

    public function comment(bool $create): GitPullRequestComment|GitHubPullRequestComment|null
    {
        return ($this->comment)($create);
    }

    public function update(int $commentId, string $body): bool
    {
        return ($this->update)($commentId, $body);
    }

    public function create(string $body): int
    {
        return ($this->create)($body);
    }
}
