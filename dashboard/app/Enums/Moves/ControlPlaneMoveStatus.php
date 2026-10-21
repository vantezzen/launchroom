<?php

declare(strict_types=1);

namespace App\Enums\Moves;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Settings → Move launchroom (docs/14 §13.4), on the old dashboard:
 *
 * waiting_for_server (the move command was shown) → claimed (the new
 * machine sent its key; both sides show a code) → joining (the owner
 * confirmed the code; the new machine's launchroomd enrolls) → joined →
 * moving (every app, database and volume moves like a server move) →
 * ready_to_hand_over → handing_over (writes frozen, final dump) →
 * handover_ready (sealed to the new machine) → handed_over (the new
 * dashboard runs and confirmed). The hand-over can be aborted before the
 * new machine confirms (back to ready_to_hand_over, writes thawed); before
 * it, the move can be cancelled.
 */
enum ControlPlaneMoveStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case WaitingForServer = 'waiting_for_server';
    case Claimed = 'claimed';
    case Joining = 'joining';
    case Joined = 'joined';
    case Moving = 'moving';
    case ReadyToHandOver = 'ready_to_hand_over';
    case HandingOver = 'handing_over';
    case HandoverReady = 'handover_ready';
    case HandedOver = 'handed_over';
    case Cancelled = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::WaitingForServer => [self::Claimed, self::Cancelled],
            self::Claimed => [self::Joining, self::Cancelled],
            self::Joining => [self::Joined, self::Cancelled],
            self::Joined => [self::Moving, self::Cancelled],
            // A retried service puts it back from ready_to_hand_over to moving.
            self::Moving => [self::ReadyToHandOver, self::Cancelled],
            self::ReadyToHandOver => [self::HandingOver, self::Moving, self::Cancelled],
            // HandedOver from handing_over: the new dashboard takes over from
            // its restored copy, where the dump caught the move in this state.
            self::HandingOver => [self::HandoverReady, self::ReadyToHandOver, self::HandedOver],
            self::HandoverReady => [self::HandedOver, self::ReadyToHandOver],
            self::HandedOver, self::Cancelled => [],
        };
    }

    /**
     * The move token (and the new machine's secret) still answer.
     */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Writes are frozen on this dashboard.
     */
    public function freezes(): bool
    {
        return in_array($this, [self::HandingOver, self::HandoverReady, self::HandedOver], true);
    }
}
