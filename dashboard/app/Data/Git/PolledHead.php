<?php

declare(strict_types=1);

namespace App\Data\Git;

/**
 * One poll of a branch head: the commit, or null when the provider answered
 * "not modified" for the validator the caller sent. `etag` is the validator
 * to send next time (GitHub only; a 304 costs no rate limit, docs/06 §2).
 */
final readonly class PolledHead
{
    public function __construct(
        public ?CommitData $commit,
        public ?string $etag = null,
    ) {}
}
