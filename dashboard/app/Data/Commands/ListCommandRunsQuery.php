<?php

declare(strict_types=1);

namespace App\Data\Commands;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * A page of a service's command runs, newest first.
 */
final class ListCommandRunsQuery extends Data
{
    public function __construct(
        #[Min(1), Max(100)]
        public int $limit = 20,
        #[Max(200), Regex('/^[A-Za-z0-9_-]+$/D')]
        public ?string $cursor = null,
    ) {}
}
