<?php

declare(strict_types=1);

namespace App\Data\Transfers\Inputs;

use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * POST …/exports (docs/14 §12.7): the data as it is now, or a backup snapshot.
 */
final class StartExportInput extends Data
{
    public function __construct(
        #[Regex('/^vol_[0-9A-HJKMNP-TV-Z]{26}$/')]
        public ?string $volume = null,
        #[Regex('/^[a-f0-9]{8,64}$/')]
        public ?string $snapshot = null,
    ) {}
}
