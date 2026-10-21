<?php

declare(strict_types=1);

namespace App\Data\Promotions;

use App\Enums\ChangeEffect;
use App\Enums\ChangeKind;
use App\Enums\PromotionClass;
use App\Enums\PromotionEntryType;
use Spatie\LaravelData\Data;

/**
 * One row of Compare & promote (docs/06 §6.1): a change made in the source
 * since the base, as it would apply to the target. `kind` is relative to the
 * target (added there, removed there, changed there). `before`/`after`/`base`
 * are readable values for settings and config variables only.
 */
final class PromotionItem extends Data
{
    /**
     * @param  list<string>  $dependsOn  items that must be selected too
     */
    public function __construct(
        public string $path,
        public PromotionEntryType $type,
        public ChangeKind $kind,
        public PromotionClass $promotion,
        public ChangeEffect $effect,
        public string $label,
        public ?string $serviceId,
        public ?string $key,
        public ?string $before,
        public ?string $after,
        public ?string $base,
        /** The target changed the same item differently since the base. */
        public bool $conflict,
        public bool $selectable,
        /** Pre-checked in the dialog. */
        public bool $selected,
        public bool $secret,
        /** Selecting it needs a value for the target (or an explicit copy). */
        public bool $needsValue,
        /** A value may be entered for the target (secrets). */
        public bool $acceptsValue,
        public bool $canCopyValue,
        public ?string $copyBlockedReason,
        public ?string $warning,
        public ?string $note,
        public array $dependsOn = [],
    ) {}
}
