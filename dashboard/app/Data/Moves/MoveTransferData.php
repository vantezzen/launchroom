<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * One entry of `server_move_items.transfers`: per volume (null: the
 * database), the fresh backup, the export and the import (public ids).
 */
final class MoveTransferData extends Data
{
    public function __construct(
        public ?string $volume = null,
        public ?string $backup = null,
        public ?string $export = null,
        public ?string $import = null,
    ) {}
}
