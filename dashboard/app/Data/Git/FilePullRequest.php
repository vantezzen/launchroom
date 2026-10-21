<?php

declare(strict_types=1);

namespace App\Data\Git;

/**
 * One file proposed as a pull request (App\Contracts\Git\OpensPullRequests).
 * `path` is repository-relative and grammar-checked by the caller; the
 * request never overwrites an existing file.
 */
final readonly class FilePullRequest
{
    public function __construct(
        public string $path,
        public string $content,
        public string $branch,
        public string $commitMessage,
        public string $title,
        public string $body,
    ) {}
}
