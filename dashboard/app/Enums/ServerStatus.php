<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Attributes\SharedFacts;
use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of an enrolled server (docs/03 §3, §9).
 *
 * pending_approval → online (approved) | revoked
 * online ⇄ unreachable → offline → online (liveness, docs/03 §9)
 * revoked is terminal: the key is denied immediately (docs/09 R25).
 */
#[SharedFacts('isDown')]
enum ServerStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case PendingApproval = 'pending_approval';
    case Online = 'online';
    case Unreachable = 'unreachable';
    case Offline = 'offline';
    case Revoked = 'revoked';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingApproval => [self::Online, self::Revoked],
            self::Online => [self::Unreachable, self::Offline, self::Revoked],
            self::Unreachable => [self::Online, self::Offline, self::Revoked],
            self::Offline => [self::Online, self::Revoked],
            self::Revoked => [],
        };
    }

    /**
     * Approved servers receive desired state and tasks.
     *
     * @return list<self>
     */
    public static function approved(): array
    {
        return [self::Online, self::Unreachable, self::Offline];
    }

    /**
     * Approved and not yet given up on: the server may be serving right now
     * (an unreachable server's containers usually keep running, docs/03 §9).
     *
     * @return list<self>
     */
    public static function reachable(): array
    {
        return [self::Online, self::Unreachable];
    }

    /**
     * Approved but not answering: reschedule and "Replace server" candidates.
     *
     * @return list<self>
     */
    public static function down(): array
    {
        return [self::Unreachable, self::Offline];
    }

    public function isApproved(): bool
    {
        return in_array($this, self::approved(), true);
    }

    public function isReachable(): bool
    {
        return in_array($this, self::reachable(), true);
    }

    public function isDown(): bool
    {
        return in_array($this, self::down(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'Waiting for approval',
            self::Online => 'Online',
            self::Unreachable => 'Unreachable',
            self::Offline => 'Offline',
            self::Revoked => 'Revoked',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Online => StatusTone::Active,
            self::PendingApproval => StatusTone::Staged,
            self::Unreachable, self::Offline => StatusTone::Failed,
            self::Revoked => StatusTone::Idle,
        };
    }
}
