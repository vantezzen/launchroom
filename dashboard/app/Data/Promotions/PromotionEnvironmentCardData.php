<?php

declare(strict_types=1);

namespace App\Data\Promotions;

use App\Enums\EnvironmentKind;
use Spatie\LaravelData\Data;

/**
 * One side of Compare & promote (flows §12): the environment as a card with
 * its live revision and last deploy.
 */
final class PromotionEnvironmentCardData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public EnvironmentKind $kind,
        public ?int $revision,
        public ?string $deployedAt,
        public int $services,
        public int $live,
    ) {}
}
