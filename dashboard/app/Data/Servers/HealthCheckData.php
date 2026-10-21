<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\HealthCheckState;
use Spatie\LaravelData\Data;

/**
 * One row of the server health section: what was checked, its state, the
 * measured value and, when not ok, what to do about it.
 */
final class HealthCheckData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public HealthCheckState $state,
        public ?string $value,
        public ?string $detail = null,
    ) {}
}
