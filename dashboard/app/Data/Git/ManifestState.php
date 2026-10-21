<?php

declare(strict_types=1);

namespace App\Data\Git;

use App\Models\Team;

/**
 * A consumed manifest state: which team started the flow, against which
 * GitHub host.
 */
final readonly class ManifestState
{
    public function __construct(
        public Team $team,
        public string $webUrl,
        public string $apiUrl,
    ) {}
}
