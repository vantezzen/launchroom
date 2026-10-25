<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * An investigation with its report (docs/22 §7.5, §9.3–9.4).
 */
final class InvestigationDetailData extends Data
{
    /**
     * @param  list<InvestigationReplicaData>  $replicas
     */
    public function __construct(
        public string $id,
        public string $status,
        public bool $automatic,
        public ?string $startedBy,
        public string $startedAt,
        public ?string $finishedAt,
        public array $replicas,
        public ?InvestigationReportData $report,
    ) {}
}
