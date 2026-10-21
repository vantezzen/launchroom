<?php

declare(strict_types=1);

namespace App\Data\Environments;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Rename an environment and/or change its deploy triggers (docs/06 §2).
 * The slug never changes (it is part of generated hostnames).
 */
final class UpdateEnvironmentData extends Data
{
    public function __construct(
        #[Min(1), Max(63)]
        public string|Optional $name = new Optional,
        public EnvironmentTriggers|Optional $triggers = new Optional,
    ) {}
}
