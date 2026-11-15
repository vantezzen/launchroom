<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use Spatie\LaravelData\Data;

/** Whether the server reaches the image registry it pulls from (D68). */
final class RegistryReachData extends Data
{
    public function __construct(
        public bool $reachable,
        public ?string $checkedAt = null,
        public ?int $latencyMs = null,
        public ?string $error = null,
    ) {}
}
