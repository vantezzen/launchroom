<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Start moving a server (docs/14 §13.2): the target and, typed, the source's
 * name.
 */
final class StartServerMoveData extends Data
{
    public function __construct(
        #[Max(40)]
        public string $target,
        #[Max(64)]
        public string $confirmation,
        public bool $backupFirst = false,
    ) {}
}
