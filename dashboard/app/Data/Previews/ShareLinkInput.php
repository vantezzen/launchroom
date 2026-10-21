<?php

declare(strict_types=1);

namespace App\Data\Previews;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

final class ShareLinkInput extends Data
{
    public function __construct(
        #[Max(100)]
        public ?string $label = null,
        #[Min(1), Max(30)]
        public int $days = 7,
    ) {}
}
