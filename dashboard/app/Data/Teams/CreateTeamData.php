<?php

declare(strict_types=1);

namespace App\Data\Teams;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * "Create team": the display name only (docs/09 R5: host-side names come
 * from ids, never from this).
 */
final class CreateTeamData extends Data
{
    public function __construct(
        // No control characters; letters, emoji and punctuation are fine.
        #[Max(64), Regex('/^[^\p{Cc}]+$/u')]
        public string $name,
    ) {}
}
