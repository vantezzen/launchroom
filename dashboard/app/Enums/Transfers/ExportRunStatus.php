<?php

declare(strict_types=1);

namespace App\Enums\Transfers;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of an export (docs/14 §12.5): launchroomd uploads the file
 * (`preparing`); a complete, checked file is `ready` to download until it
 * expires.
 */
enum ExportRunStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Preparing = 'preparing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Preparing => [self::Ready, self::Failed],
            self::Ready => [self::Expired],
            self::Failed, self::Expired => [],
        };
    }
}
