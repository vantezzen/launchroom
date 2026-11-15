<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * The move page's query: the target the plan is shown for, if picked.
 */
final class ServerMoveTargetData extends Data
{
    public function __construct(
        #[Max(40)]
        public ?string $target = null,
    ) {}
}
