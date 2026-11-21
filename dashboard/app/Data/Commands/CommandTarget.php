<?php

declare(strict_types=1);

namespace App\Data\Commands;

use App\Data\Protocol\V1\Tasks\RunOnceMount;
use App\Models\Server;

/**
 * Where a one-off command runs and the volumes it mounts (D105).
 */
final readonly class CommandTarget
{
    /**
     * @param  list<RunOnceMount>  $mounts
     */
    public function __construct(
        public Server $server,
        public array $mounts = [],
    ) {}
}
