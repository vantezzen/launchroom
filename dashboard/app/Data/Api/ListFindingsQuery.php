<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * `GET /api/v1/security/findings` filters (docs/22 §11).
 */
final class ListFindingsQuery extends Data
{
    public function __construct(
        #[In(['action_needed', 'worth_knowing'])]
        public ?string $level = null,
        #[In(['open', 'dismissed', 'resolved'])]
        public string $status = 'open',
        #[Regex('/^prj_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public ?string $project = null,
        #[Regex('/^svc_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public ?string $service = null,
        #[Min(1), Max(100)]
        public int $limit = 50,
        #[Max(200), Regex('/^[A-Za-z0-9_-]+$/D')]
        public ?string $cursor = null,
    ) {}
}
