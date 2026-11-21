<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

final class RobotScopeServiceData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
