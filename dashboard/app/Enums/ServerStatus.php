<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of an enrolled server (docs/03 §3, §9).
 *
 * pending_approval → online (approved) | revoked
 * online ⇄ unreachable → offline → online (liveness, docs/03 §9)
 * revoked is terminal: the key is denied immediately (docs/09 R25).
 */
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
     */
    public function isApproved(): bool
    {
        return in_array($this, [self::Online, self::Unreachable, self::Offline], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'Pending approval',
            self::Online => 'Online',
            self::Unreachable => 'Unreachable',
            self::Offline => 'Offline',
            self::Revoked => 'Revoked',
        };
    }
}
