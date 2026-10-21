<?php

declare(strict_types=1);

namespace App\Data\Teams;

use Spatie\LaravelData\Data;

final class ConsoleRecordingData extends Data
{
    public function __construct(
        public bool $enabled,
    ) {}
}
