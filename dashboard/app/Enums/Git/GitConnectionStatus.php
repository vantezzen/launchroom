<?php

declare(strict_types=1);

namespace App\Enums\Git;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a git connection (docs/19 §4): `pending` until OAuth
 * completes or a plain git server's host key is confirmed, `active` while
 * launchroom can use it, `needs_reauth` after a refused token refresh or a
 * rejected token (signing in again makes it active).
 */
enum GitConnectionStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Pending = 'pending';
    case Active = 'active';
    case NeedsReauth = 'needs_reauth';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Active],
            self::Active => [self::NeedsReauth, self::Active],
            self::NeedsReauth => [self::Active, self::NeedsReauth],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not finished',
            self::Active => 'Connected',
            self::NeedsReauth => 'Needs you to sign in again',
        };
    }
}
