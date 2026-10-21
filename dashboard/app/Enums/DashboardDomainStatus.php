<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a dashboard domain change (Settings → Dashboard domain,
 * docs/05 §4.1, D43).
 *
 * pending: DNS, certificate or the HTTPS test request isn't done yet (checked
 * again every minute). ready: all three passed; the owner may switch. A check
 * that stops passing sends it back to pending. active: the canonical origin.
 * cancelled: dropped before switching. reverted: was active, then the owner
 * switched back, switched to another domain, or `launchroom:dashboard-domain:reset` ran.
 */
enum DashboardDomainStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Pending = 'pending';
    case Ready = 'ready';
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Reverted = 'reverted';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Ready, self::Cancelled],
            self::Ready => [self::Pending, self::Active, self::Cancelled],
            self::Active => [self::Reverted],
            self::Cancelled, self::Reverted => [],
        };
    }

    /**
     * Still being set up (at most one such change exists).
     */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Ready;
    }
}
