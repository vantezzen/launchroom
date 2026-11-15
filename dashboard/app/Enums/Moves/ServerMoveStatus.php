<?php

declare(strict_types=1);

namespace App\Enums\Moves;

use App\Enums\Attributes\SharedFacts;
use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a server move (docs/14 §13.2):
 *
 * running → ready once every service runs on the target; ready → completed
 * when the source is retired (or, for the control plane, handed over);
 * running | ready → cancelling (services that haven't started on the target
 * go back) → cancelled.
 */
#[SharedFacts('isOpen', 'isAdvancing')]
enum ServerMoveStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Running = 'running';
    case Ready = 'ready';
    case Cancelling = 'cancelling';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Running => [self::Ready, self::Cancelling],
            // A retried item puts a ready move back to running.
            self::Ready => [self::Completed, self::Cancelling, self::Running],
            self::Cancelling => [self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    /**
     * The move is open: its source drains and nothing else may move it.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Running, self::Ready, self::Cancelling];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /**
     * Neither ended nor being cancelled: the source drains, and the move can
     * still be cancelled and its items retried.
     *
     * @return list<self>
     */
    public static function underway(): array
    {
        return [self::Running, self::Ready];
    }

    public function isUnderway(): bool
    {
        return in_array($this, self::underway(), true);
    }

    /**
     * The move still has steps to take (AdvanceServerMoves works on it).
     *
     * @return list<self>
     */
    public static function advancing(): array
    {
        return [self::Running, self::Cancelling];
    }

    public function isAdvancing(): bool
    {
        return in_array($this, self::advancing(), true);
    }

    /**
     * @param  list<self>  $statuses
     * @return list<string>
     */
    public static function values(array $statuses): array
    {
        return array_map(static fn (self $status): string => $status->value, $statuses);
    }
}
