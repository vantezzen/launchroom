<?php

declare(strict_types=1);

namespace App\Enums\Traces;

/**
 * Why the edge couldn't get an answer from the app (docs/23 §2.3): the
 * byte value of the trace's `failure` field.
 */
enum TraceFailure: string
{
    case Refused = 'refused';
    case Timeout = 'timeout';
    case NoHealthyUpstream = 'no_healthy_upstream';
    case Transport = 'transport';
    case ClientClosed = 'client_closed';

    public static function fromByte(int $value): ?self
    {
        return match ($value) {
            1 => self::Refused,
            2 => self::Timeout,
            3 => self::NoHealthyUpstream,
            4 => self::Transport,
            5 => self::ClientClosed,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Refused => 'Connection refused',
            self::Timeout => 'Timed out',
            self::NoHealthyUpstream => 'No healthy replica',
            self::Transport => 'Connection error',
            self::ClientClosed => 'Visitor closed the connection',
        };
    }
}
