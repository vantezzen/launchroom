<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * `server_moves.options`: what the owner chose when starting the move.
 */
final class ServerMoveOptionsData extends Data
{
    public function __construct(
        public bool $backupFirst = false,
    ) {}
}
