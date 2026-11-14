<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\EnvironmentKind;
use Spatie\LaravelData\Data;

/**
 * An environment on the projects list: service count, aggregate status and
 * the services that failed or need attention (most urgent first).
 */
final class EnvironmentSummaryData extends Data
{
    /**
     * @param  list<ServiceProblemData>  $problems
     */
    public function __construct(
        public string $id,
        public string $name,
        public EnvironmentKind $kind,
        public int $services,
        public NodeStatusData $status,
        public array $problems = [],
    ) {}
}
