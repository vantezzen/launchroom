<?php

declare(strict_types=1);

namespace App\Data\Attributes;

use App\Enums\ChangeEffect;
use Attribute;

/**
 * Declares what a change of this property needs when deployed (docs/06 §4,
 * docs/04 §7: build-affecting vs runtime-only). Read by App\Support\Changes\SpecDiff.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class ChangeAffects
{
    public function __construct(
        public ChangeEffect $effect,
    ) {}
}
