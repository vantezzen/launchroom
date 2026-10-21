<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\EnvironmentKind;
use Spatie\LaravelData\Data;

final class EnvironmentRefData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public EnvironmentKind $kind,
    ) {}
}
