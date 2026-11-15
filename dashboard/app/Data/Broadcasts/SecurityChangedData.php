<?php

declare(strict_types=1);

namespace App\Data\Broadcasts;

use Spatie\LaravelData\Data;

/** `security.changed`: the team's findings were re-evaluated. */
final class SecurityChangedData extends Data
{
    public function __construct(
        public string $teamId,
    ) {}
}
