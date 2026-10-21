<?php

declare(strict_types=1);

namespace App\Data\Transfers\Inputs;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

final class OwnerInput extends Data
{
    public function __construct(
        #[Min(0), Max(2147483647)]
        public int $uid,
        #[Min(0), Max(2147483647)]
        public int $gid,
    ) {}
}
