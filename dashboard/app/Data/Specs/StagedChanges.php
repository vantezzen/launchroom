<?php

declare(strict_types=1);

namespace App\Data\Specs;

use App\Enums\ChangeEffect;
use Spatie\LaravelData\Data;

/**
 * Result of App\Support\Changes\SpecDiff.
 */
final class StagedChanges extends Data
{
    /**
     * @param  list<ChangeItem>  $items
     * @param  array<string, ChangeEffect>  $services  effect per service public id (including
     *                                                 restarts propagated through references)
     */
    public function __construct(
        public array $items = [],
        public array $services = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function effectFor(string $serviceId): ChangeEffect
    {
        return $this->services[$serviceId] ?? ChangeEffect::None;
    }

    /**
     * Items whose path equals one of $paths or lies below it.
     *
     * @param  list<string>  $paths
     * @return list<ChangeItem>
     */
    public function itemsUnder(array $paths): array
    {
        return array_values(array_filter($this->items, static fn (ChangeItem $item): bool => array_any($paths, fn (string $path): bool => $item->path === $path || str_starts_with($item->path, $path.'.'))));
    }
}
