<?php

declare(strict_types=1);

namespace App\Enums\Transfers;

use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * Lifecycle of a transfer (docs/14 §12.1): chunks arrive (`receiving`), the
 * complete, checked file is `ready`; `failed` and `expired` files are deleted.
 */
enum TransferStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Receiving = 'receiving';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Receiving => [self::Ready, self::Failed, self::Expired],
            self::Ready => [self::Expired, self::Failed],
            self::Failed, self::Expired => [],
        };
    }
}
