<?php

declare(strict_types=1);

namespace App\Data\Attributes;

use App\Enums\PromotionClass;
use Attribute;

/**
 * Declares how a property is promoted between environments (docs/06 §6.2).
 * Read by App\Support\Changes\FieldMeta; the promotion flow uses it later.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Promotion
{
    public function __construct(
        public PromotionClass $class,
    ) {}
}
