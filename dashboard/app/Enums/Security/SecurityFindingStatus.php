<?php

declare(strict_types=1);

namespace App\Enums\Security;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a finding (docs/22 §5.5): open → resolved (the live
 * deployment no longer has the version, the advisory was withdrawn, the
 * instance was removed) or dismissed ("Not affected"); dismissed → open
 * (reopened by a user, or when the level rises or the advisory becomes
 * known exploited) or resolved; resolved → open (the version came back).
 */
enum SecurityFindingStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Open = 'open';
    case Dismissed = 'dismissed';
    case Resolved = 'resolved';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Resolved, self::Dismissed],
            self::Dismissed => [self::Open, self::Resolved],
            self::Resolved => [self::Open],
        };
    }
}
