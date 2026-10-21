<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

final class AddDomainData extends Data
{
    public function __construct(
        #[Min(1), Max(253)]
        public string $hostname,
        #[Max(253)]
        public ?string $redirectTo = null,
    ) {}
}
