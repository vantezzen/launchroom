<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * "Not affected" (docs/22 §5.5): why a person dismissed a finding.
 */
enum DismissReason: string
{
    case NotUsed = 'not_used';
    case Mitigated = 'mitigated';
    case Accepted = 'accepted';

    public function label(): string
    {
        return match ($this) {
            self::NotUsed => 'We don’t use the affected code',
            self::Mitigated => 'Mitigated another way',
            self::Accepted => 'Accepted risk',
        };
    }
}
