<?php

declare(strict_types=1);

namespace App\Data\Git;

use App\Support\Git\PublicRepositoryUrl;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * A pasted repository URL on New project (docs/19 §4.3). The grammar is
 * checked by {@see PublicRepositoryUrl}.
 */
final class PublicRepositoryUrlData extends Data
{
    public function __construct(
        #[Max(512)]
        public string $url,
    ) {}
}
