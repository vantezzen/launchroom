<?php

declare(strict_types=1);

namespace App\Data\Git;

use App\Services\GitHub\Dto\BranchInfo;
use Spatie\LaravelData\Data;

final class GitBranchData extends Data
{
    public function __construct(
        public string $name,
        public string $sha,
        public bool $protected,
        public bool $isDefault,
    ) {}

    public static function fromBranch(BranchInfo $branch, ?string $defaultBranch): self
    {
        return new self($branch->name, $branch->sha, $branch->protected, $branch->name === $defaultBranch);
    }
}
