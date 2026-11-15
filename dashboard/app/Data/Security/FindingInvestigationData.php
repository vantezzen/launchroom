<?php

declare(strict_types=1);

namespace App\Data\Security;

use App\Enums\Security\InvestigationStatus;
use Spatie\LaravelData\Data;

/**
 * The latest investigation of a finding, as a line on a card (docs/22 §9.2).
 */
final class FindingInvestigationData extends Data
{
    /**
     * @param  list<string>  $summary
     */
    public function __construct(
        public string $id,
        public InvestigationStatus $status,
        public string $startedAt,
        public ?string $finishedAt,
        public array $summary,
    ) {}
}
