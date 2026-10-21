<?php

declare(strict_types=1);

namespace App\Enums\Sessions;

/**
 * Why an interactive session ended (protocol SessionEndReason, docs/03 §11.1),
 * plus the dashboard's own reasons for sessions no server ran.
 */
enum SessionEndReason: string
{
    case Closed = 'closed';
    case Exited = 'exited';
    case Idle = 'idle';
    case Deadline = 'deadline';
    case Disconnected = 'disconnected';
    case Ended = 'ended';
    case NeverConnected = 'never_connected';
    case Refused = 'refused';
    case Error = 'error';

    public function sentence(): string
    {
        return match ($this) {
            self::Closed => 'Closed',
            self::Exited => 'The shell exited',
            self::Idle => 'Ended after a while without input',
            self::Deadline => 'Reached the time limit',
            self::Disconnected => 'The browser disconnected',
            self::Ended => 'Ended from the dashboard',
            self::NeverConnected => 'The browser never connected',
            self::Refused => 'The server doesn’t allow this',
            self::Error => 'Something went wrong',
        };
    }
}
