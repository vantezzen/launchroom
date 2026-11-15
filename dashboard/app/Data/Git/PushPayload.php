<?php

declare(strict_types=1);

namespace App\Data\Git;

/**
 * A push as a provider's webhook described it, before launchroom records the
 * head (docs/19 §5). `$after` may be the zero SHA (branch deleted).
 */
final readonly class PushPayload
{
    /**
     * @param  int  $repositoryId  the provider's numeric repository id
     * @param  string  $ref  full ref, `refs/heads/…` or `refs/tags/…`
     * @param  list<string>|null  $changedFiles  null when unknown (too many commits in the push)
     */
    public function __construct(
        public int $repositoryId,
        public string $ref,
        public string $before,
        public string $after,
        public CommitData $headCommit,
        public ?array $changedFiles,
        public bool $forced,
    ) {}
}
