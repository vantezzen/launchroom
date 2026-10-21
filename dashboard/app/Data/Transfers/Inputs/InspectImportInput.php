<?php

declare(strict_types=1);

namespace App\Data\Transfers\Inputs;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * POST …/imports/inspect: look at the target (and check a connection URL).
 */
final class InspectImportInput extends Data
{
    public function __construct(
        #[Regex('/^vol_[0-9A-HJKMNP-TV-Z]{26}$/')]
        public ?string $volume = null,
        #[Max(2048)]
        public ?string $sourceUrl = null,
    ) {}
}
