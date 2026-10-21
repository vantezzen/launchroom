<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

final class ListDeploymentsQuery extends Data
{
    public function __construct(
        #[Regex('/^svc_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public ?string $service = null,
        #[Min(1), Max(100)]
        public int $limit = 25,
        #[Max(200), Regex('/^[A-Za-z0-9_-]+$/D')]
        public ?string $cursor = null,
    ) {}
}
