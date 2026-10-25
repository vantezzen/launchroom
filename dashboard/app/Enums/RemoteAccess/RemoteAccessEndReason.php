<?php

declare(strict_types=1);

namespace App\Enums\RemoteAccess;

/**
 * Why a temporary access ended (AccessResult.endReason, docs/03 §12.1).
 */
enum RemoteAccessEndReason: string
{
    case Ended = 'ended';
    case Idle = 'idle';
    case Deadline = 'deadline';
    case Locked = 'locked';
    case Refused = 'refused';
    case Error = 'error';

    public function sentence(): string
    {
        return match ($this) {
            self::Ended => 'Ended.',
            self::Idle => 'Ended after it went unused.',
            self::Deadline => 'Reached its time limit.',
            self::Locked => 'Ended after too many wrong passwords.',
            self::Refused => 'The server doesn’t allow this access.',
            self::Error => 'Ended with an error.',
        };
    }
}
