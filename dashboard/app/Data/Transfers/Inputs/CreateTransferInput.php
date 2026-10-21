<?php

declare(strict_types=1);

namespace App\Data\Transfers\Inputs;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * POST …/transfers: announce an upload (docs/14 §12.7).
 */
final class CreateTransferInput extends Data
{
    public function __construct(
        #[In(['database_import', 'volume_import'])]
        public string $purpose,
        #[Min(1), Max(255)]
        public string $fileName,
        #[Min(1)]
        public int $sizeBytes,
        #[Regex('/^vol_[0-9A-HJKMNP-TV-Z]{26}$/')]
        public ?string $volume = null,
    ) {}
}
