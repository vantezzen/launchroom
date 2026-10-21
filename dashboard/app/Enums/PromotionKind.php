<?php

declare(strict_types=1);

namespace App\Enums;

enum PromotionKind: string
{
    /** The target was created as a copy of the source. */
    case Created = 'created';
    /** Changes were staged in the target with Compare & promote. */
    case Promoted = 'promoted';
}
