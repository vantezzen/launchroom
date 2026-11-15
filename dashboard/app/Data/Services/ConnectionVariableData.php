<?php

declare(strict_types=1);

namespace App\Data\Services;

use Spatie\LaravelData\Data;

/**
 * One entry of `services.connection_variables`: a variable a connection to
 * the service injects, its value with references to the service (D65).
 */
final class ConnectionVariableData extends Data
{
    public function __construct(
        public string $key,
        public string $value,
    ) {}
}
