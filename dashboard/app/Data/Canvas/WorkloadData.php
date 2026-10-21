<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\WorkloadPhase;
use Spatie\LaravelData\Data;

final class WorkloadData extends Data
{
    public function __construct(
        public string $id,
        public string $server,
        public WorkloadPhase $phase,
        public ?string $containerId,
        public int $restartCount,
    ) {}
}
