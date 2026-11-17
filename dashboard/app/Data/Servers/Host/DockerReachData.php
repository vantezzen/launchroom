<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use Spatie\LaravelData\Data;

/** Whether launchroomd reaches Docker through dockerd-guard (Report.host.docker). */
final class DockerReachData extends Data
{
    public function __construct(
        public bool $reachable,
        public string $checkedAt,
        public ?string $error = null,
    ) {}
}
