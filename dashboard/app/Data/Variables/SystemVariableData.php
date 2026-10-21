<?php

declare(strict_types=1);

namespace App\Data\Variables;

use Spatie\LaravelData\Data;

/**
 * A variable launchroom injects (docs/06 §4.1 "System variables"); read-only.
 */
final class SystemVariableData extends Data
{
    public function __construct(
        public string $key,
        public string $value,
    ) {}
}
