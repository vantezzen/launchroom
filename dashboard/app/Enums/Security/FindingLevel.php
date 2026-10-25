<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * The two alert levels (docs/22 §5): "Action needed" notifies and leads the
 * Security page; "Worth knowing" is listed quietly and never notifies.
 */
enum FindingLevel: string
{
    case ActionNeeded = 'action_needed';
    case WorthKnowing = 'worth_knowing';

    public function label(): string
    {
        return match ($this) {
            self::ActionNeeded => 'Action needed',
            self::WorthKnowing => 'Worth knowing',
        };
    }
}
