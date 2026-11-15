<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Data\Git\CommitData;
use App\Models\Deployment;
use Spatie\LaravelData\Data;

/**
 * The commit a new deployment runs and how it is shown (message, author).
 */
final class DeploymentCommit extends Data
{
    public const int MESSAGE_LIMIT = 4000;

    public function __construct(
        public ?string $sha,
        public ?string $ref = null,
        public ?string $message = null,
        public ?string $author = null,
        public ?string $authorAvatarUrl = null,
    ) {}

    public static function pushed(CommitData $commit, string $ref): self
    {
        return new self(
            sha: $commit->sha,
            ref: $ref,
            message: mb_substr($commit->message, 0, self::MESSAGE_LIMIT),
            author: $commit->authorLogin ?? $commit->authorName,
            authorAvatarUrl: $commit->authorAvatarUrl,
        );
    }

    /**
     * What $source ran (an image service has no sha, but may show a commit).
     */
    public static function of(?Deployment $source): ?self
    {
        if (! $source instanceof Deployment) {
            return null;
        }

        return new self($source->commit_sha, $source->git_ref, $source->commit_message, $source->commit_author, $source->commit_author_avatar_url);
    }
}
