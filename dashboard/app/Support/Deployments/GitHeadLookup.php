<?php

declare(strict_types=1);

namespace App\Support\Deployments;

/**
 * The commit a deployment without one builds: the head of its branch, or
 * why it could not be resolved (App\Exceptions\GitHeadUnavailable message).
 */
final readonly class GitHeadLookup
{
    private function __construct(
        public ?string $branch,
        public ?string $sha,
        public ?string $error,
    ) {}

    public static function resolved(string $branch, string $sha): self
    {
        return new self($branch, $sha, null);
    }

    public static function failed(string $error): self
    {
        return new self(null, null, $error);
    }
}
