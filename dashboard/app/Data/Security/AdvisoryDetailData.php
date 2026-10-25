<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * An advisory group's public record (docs/22 §9.3): plain text only
 * (rendered as paragraphs and https links), references and the source.
 */
final class AdvisoryDetailData extends Data
{
    /**
     * @param  list<string>  $ids
     * @param  list<string>  $cwe
     * @param  list<InvestigationLinkData>  $references
     */
    public function __construct(
        public array $ids,
        public ?string $summary,
        public ?string $details,
        public ?string $published,
        public ?string $modified,
        public ?string $cvssV3,
        public ?string $cvssV4,
        public array $cwe,
        public array $references,
        public string $source,
    ) {}
}
