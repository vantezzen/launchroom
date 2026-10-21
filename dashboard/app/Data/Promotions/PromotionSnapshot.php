<?php

declare(strict_types=1);

namespace App\Data\Promotions;

use App\Enums\PromotionEntryType;
use Spatie\LaravelData\Data;

/**
 * An environment's intent in promotable form (App\Services\Promotions\PromotionSnapshots):
 * entries by logical path. Stored with every environment promotion as the
 * base of the next three-way comparison (docs/06 §6.3).
 */
final class PromotionSnapshot extends Data
{
    /**
     * @param  array<string, PromotionEntry>  $entries
     */
    public function __construct(
        public array $entries = [],
    ) {}

    public function get(string $path): ?PromotionEntry
    {
        return $this->entries[$path] ?? null;
    }

    /**
     * The form that is stored: no variable values (settings stay readable
     * for "was …" in conflicts; they are never secret).
     */
    public function withoutDisplays(): self
    {
        $entries = [];

        foreach ($this->entries as $path => $entry) {
            $copy = clone $entry;

            if (in_array($entry->type, [PromotionEntryType::Variable, PromotionEntryType::SharedVariable], true)) {
                $copy->display = null;
            }

            $entries[$path] = $copy;
        }

        return new self($entries);
    }
}
