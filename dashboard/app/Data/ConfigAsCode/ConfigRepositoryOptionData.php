<?php

declare(strict_types=1);

namespace App\Data\ConfigAsCode;

use Spatie\LaravelData\Data;

final class ConfigRepositoryOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $fullName,
    ) {}
}
