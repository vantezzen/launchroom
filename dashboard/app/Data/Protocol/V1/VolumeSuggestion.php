<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * BuildSummary `volumeSuggestions[]` (protocol VolumeSuggestion): a folder
 * whose files should outlive a deploy (uploads, a SQLite database).
 */
final class VolumeSuggestion extends Data
{
    public function __construct(
        #[Regex(Grammar::CONTAINER_PATH)]
        public string $path,
        #[Max(256)]
        public string $reason,
    ) {}
}
