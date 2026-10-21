<?php

declare(strict_types=1);

namespace App\Drivers\DatabaseEngines;

use App\Enums\VariableType;

/**
 * A variable an engine creates for each environment of a database service.
 * `value` may contain generator functions (`${{ secret(32) }}`, evaluated once
 * at creation) and references (`${{PRIVATE_HOST}}`, resolved per deploy).
 */
final readonly class DriverVariable
{
    public function __construct(
        public string $key,
        public string $value,
        public VariableType $type = VariableType::Config,
    ) {}
}
