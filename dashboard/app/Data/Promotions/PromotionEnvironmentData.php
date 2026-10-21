<?php

declare(strict_types=1);

namespace App\Data\Promotions;

use App\Enums\EnvironmentKind;
use Spatie\LaravelData\Data;

final class PromotionEnvironmentData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public EnvironmentKind $kind,
    ) {}
}
