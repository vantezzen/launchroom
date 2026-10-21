<?php

declare(strict_types=1);

namespace App\Data\Services;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

final class RenameServiceData extends Data
{
    public function __construct(
        #[Min(1), Max(63)]
        public string $name,
    ) {}
}
