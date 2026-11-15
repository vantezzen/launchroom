<?php

declare(strict_types=1);

namespace App\Data\Git;

use Spatie\LaravelData\Data;

final class GitBranchData extends Data
{
    public function __construct(
        public string $name,
        public string $sha,
        public bool $protected,
        public bool $isDefault,
    ) {}
}
