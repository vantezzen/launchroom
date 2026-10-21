<?php

declare(strict_types=1);

namespace App\Data\StagedChanges;

use App\Data\Specs\ChangeItem;
use App\Enums\ChangeEffect;
use Spatie\LaravelData\Data;

/**
 * Staged changes of one service (or of the environment itself when
 * `serviceId` is null), with the effect deploying them has (docs/06 §4).
 */
final class StagedServiceData extends Data
{
    /**
     * @param  list<ChangeItem>  $items
     */
    public function __construct(
        public ?string $serviceId,
        public string $name,
        public ?string $slug,
        public ChangeEffect $effect,
        public array $items,
    ) {}
}
