<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

final class CanvasPositionData extends Data
{
    public function __construct(
        #[Min(-100000), Max(100000)]
        public float $x,
        #[Min(-100000), Max(100000)]
        public float $y,
    ) {}
}
