<?php

declare(strict_types=1);

namespace App\Enums;

enum DeploymentStepStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Running => StatusTone::Progress,
            self::Succeeded => StatusTone::Active,
            self::Failed => StatusTone::Failed,
            self::Skipped => StatusTone::Idle,
        };
    }
}
