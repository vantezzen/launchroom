<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a stateful service does in previews (docs/06 §5).
 */
enum PreviewStrategy: string
{
    case NewEmpty = 'new_empty';
    case Clone = 'clone';
    case Share = 'share';
}
