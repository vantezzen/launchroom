<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use Spatie\LaravelData\Data;

/**
 * The start check of a run_once task (protocol RunOnceProbe, docs/20 §5).
 */
final class RunOnceProbe extends Data
{
    public function __construct(
        public int $port,
        public string $within,
        public ?string $path = null,
    ) {}
}
