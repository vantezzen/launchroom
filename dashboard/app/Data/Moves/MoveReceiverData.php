<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * `control_plane_moves.receiver`: the machine that claimed the move.
 */
final class MoveReceiverData extends Data
{
    public function __construct(
        public ?string $hostname = null,
        public ?string $arch = null,
        public ?string $version = null,
        public ?string $address = null,
    ) {}
}
