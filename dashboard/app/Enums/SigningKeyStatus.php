<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Control-plane signing keys (docs/07 `signing_keys`). Active keys are sent to
 * daemons as their trusted key set; retired keys no longer are.
 */
enum SigningKeyStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Active = 'active';
    case Retired = 'retired';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Retired],
            self::Retired => [],
        };
    }
}
