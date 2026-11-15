<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * Move the control plane's apps and data to the new server, optionally
 * backing up stateful services first.
 */
final class MoveWorkloadsData extends Data
{
    public function __construct(
        public bool $backupFirst = false,
    ) {}
}
