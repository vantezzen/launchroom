<?php

declare(strict_types=1);

namespace App\Enums\Transfers;

/**
 * Where a copy's step stands (docs/14 §14.2).
 */
enum CopyStepState: string
{
    case Done = 'done';
    case Current = 'current';
    case Waiting = 'waiting';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';
}
