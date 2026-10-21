<?php

declare(strict_types=1);

namespace App\Enums\Backups;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of one restore verification (docs/14 §10.4). `failed` covers
 * both a snapshot that did not restore or check cleanly and a verification
 * that could not run (the error says which).
 */
enum VerificationStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Pending = 'pending';
    case Running = 'running';
    case Passed = 'passed';
    case Failed = 'failed';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Running, self::Passed, self::Failed],
            self::Running => [self::Passed, self::Failed],
            self::Passed, self::Failed => [],
        };
    }
}
