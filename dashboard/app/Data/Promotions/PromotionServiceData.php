<?php

declare(strict_types=1);

namespace App\Data\Promotions;

use App\Enums\ServiceKind;
use Spatie\LaravelData\Data;

final class PromotionServiceData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public ServiceKind $kind,
    ) {}
}
