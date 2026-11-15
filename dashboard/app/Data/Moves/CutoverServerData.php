<?php

declare(strict_types=1);

namespace App\Data\Moves;

use App\Enums\ServerStatus;
use Spatie\LaravelData\Data;

/**
 * Another server of the team after a control-plane cutover, and whether it
 * talked to the new control plane since the cutover started.
 */
final class CutoverServerData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ServerStatus $status,
        public ?string $lastSeenAt,
        public bool $reconnected,
    ) {}
}
