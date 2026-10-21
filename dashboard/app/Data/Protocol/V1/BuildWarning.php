<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * A non-fatal finding shown with the plan; also used for failure hints.
 */
final class BuildWarning extends Data
{
    public function __construct(
        #[Max(64)]
        public string $code,
        #[Max(1024)]
        public string $message,
    ) {}
}
