<?php

declare(strict_types=1);

namespace App\Enums\Git;

use App\Enums\StatusTone;

/**
 * One CI check on a commit, as launchroom reads it from the git provider
 * (D111, docs/06 §2.5): each provider's states map onto these three.
 */
enum CommitCheckState: string
{
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Running',
            self::Passed => 'Passed',
            self::Failed => 'Failed',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Pending => StatusTone::Progress,
            self::Passed => StatusTone::Active,
            self::Failed => StatusTone::Failed,
        };
    }
}
