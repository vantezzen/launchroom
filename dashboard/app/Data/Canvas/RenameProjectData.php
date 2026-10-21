<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

final class RenameProjectData extends Data
{
    public function __construct(
        #[Min(1), Max(100)]
        public string $name,
    ) {}
}
