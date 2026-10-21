<?php

declare(strict_types=1);

namespace App\Data\Transfers\Inputs;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * POST …/imports (docs/14 §12.7): exactly one of transfer and sourceUrl.
 */
final class StartImportInput extends Data
{
    public function __construct(
        #[Regex('/^dtr_[0-9A-HJKMNP-TV-Z]{26}$/')]
        public ?string $transfer = null,
        #[Max(2048)]
        public ?string $sourceUrl = null,
        public bool $replace = false,
        public bool $stopDependents = true,
        #[Max(63)]
        public ?string $confirmation = null,
        #[Regex('/^vol_[0-9A-HJKMNP-TV-Z]{26}$/')]
        public ?string $volume = null,
        #[Min(0), Max(8)]
        public int $stripComponents = 0,
        public ?OwnerInput $owner = null,
    ) {}
}
