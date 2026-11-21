<?php

declare(strict_types=1);

namespace App\Enums\AppUpdates;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;
use App\Enums\StatusTone;

/**
 * One update of an image service (docs/04 §14.2):
 *
 * backing_up → deploying → watching → succeeded; a failed backup or
 * deployment ends as failed, a probation rollback as rolled_back, and a
 * backup that is still running can be cancelled.
 */
enum AppUpdateStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case BackingUp = 'backing_up';
    case Deploying = 'deploying';
    case Watching = 'watching';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
    case Cancelled = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::BackingUp => [self::Deploying, self::Failed, self::Cancelled],
            self::Deploying => [self::Watching, self::Succeeded, self::Failed],
            self::Watching => [self::Succeeded, self::RolledBack],
            self::Succeeded, self::Failed, self::RolledBack, self::Cancelled => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::BackingUp => 'Backing up',
            self::Deploying => 'Deploying',
            self::Watching => 'Watching',
            self::Succeeded => 'Updated',
            self::Failed => 'Failed',
            self::RolledBack => 'Rolled back',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::BackingUp, self::Deploying, self::Watching => StatusTone::Progress,
            self::Succeeded => StatusTone::Active,
            self::Failed, self::RolledBack => StatusTone::Failed,
            self::Cancelled => StatusTone::Idle,
        };
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::BackingUp, self::Deploying, self::Watching];
    }
}
