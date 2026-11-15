<?php

declare(strict_types=1);

namespace App\Enums\Git;

/**
 * What happened to a pull request, in GitHub's words; each provider maps its
 * own webhook actions onto these (docs/19 §5). Actions launchroom doesn't act
 * on are `Other`.
 */
enum PullRequestAction: string
{
    case Opened = 'opened';
    case Reopened = 'reopened';
    case Synchronize = 'synchronize';
    case Edited = 'edited';
    case Closed = 'closed';
    case Labeled = 'labeled';
    case Unlabeled = 'unlabeled';
    case Other = 'other';

    /**
     * New code, or the PR reopened: changed files are read, and a fork
     * author's permission is looked up (docs/09 R20).
     */
    public function isCodeEvent(): bool
    {
        return in_array($this, [self::Opened, self::Reopened, self::Synchronize], true);
    }
}
