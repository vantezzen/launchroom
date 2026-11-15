<?php

declare(strict_types=1);

namespace App\Data\Variables;

use Spatie\LaravelData\Data;

/**
 * "Use in this service" for a shared variable, on or off.
 */
final class SharedVariableUsageData extends Data
{
    public function __construct(
        public bool $used,
    ) {}
}
