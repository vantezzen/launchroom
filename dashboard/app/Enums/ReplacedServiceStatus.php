<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Attributes\SharedFacts;

/**
 * How far one service of a "Replace server" run got
 * (`server_replacements.services[*].status`, docs/14 §5.3).
 */
#[SharedFacts('isDone')]
enum ReplacedServiceStatus: string
{
    case Pending = 'pending';
    case Deploying = 'deploying';
    case Restoring = 'restoring';
    case Restored = 'restored';
    case Done = 'done';
    case Running = 'running';
    case Failed = 'failed';
    case Gone = 'gone';

    /** Nothing more happens to it; the run completes once every service is done. */
    public function isDone(): bool
    {
        return in_array($this, [self::Restored, self::Done, self::Running, self::Failed, self::Gone], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Deploying => 'Starting on the replacement',
            self::Restoring => 'Restoring from backup',
            self::Restored => 'Restored',
            self::Done => 'Running',
            self::Running => 'Already running elsewhere',
            self::Failed => 'Needs attention',
            self::Gone => 'Removed meanwhile',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Pending, self::Deploying, self::Restoring => StatusTone::Progress,
            self::Restored, self::Done, self::Running => StatusTone::Active,
            self::Failed => StatusTone::Failed,
            self::Gone => StatusTone::Idle,
        };
    }
}
