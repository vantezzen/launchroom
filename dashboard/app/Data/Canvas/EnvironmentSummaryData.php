<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\EnvironmentKind;
use Spatie\LaravelData\Data;

/**
 * An environment on the projects list: service count and aggregate status.
 */
final class EnvironmentSummaryData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public EnvironmentKind $kind,
        public int $services,
        public NodeStatusData $status,
    ) {}
}
