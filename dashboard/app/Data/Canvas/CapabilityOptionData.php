<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\WorkloadCapability;
use Spatie\LaravelData\Data;

/**
 * One capability Advanced offers as a toggle: launchroomd's allow-list only
 * (docs/03 §8), with what it allows in plain words.
 */
final class CapabilityOptionData extends Data
{
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
    ) {}

    /**
     * @return list<self>
     */
    public static function options(): array
    {
        return array_map(static fn (WorkloadCapability $capability): self => new self($capability->value, $capability->label(), $capability->description()), WorkloadCapability::cases());
    }
}
