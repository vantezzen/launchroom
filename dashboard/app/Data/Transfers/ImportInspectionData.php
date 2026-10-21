<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use Spatie\LaravelData\Data;

/**
 * What an inspection found (docs/14 §12.2): the target, and for a connection
 * URL whether it answered.
 */
final class ImportInspectionData extends Data
{
    /**
     * @param  'pending'|'done'|'failed'  $status
     */
    public function __construct(
        public string $id,
        public string $status,
        public ?bool $empty = null,
        public ?int $tables = null,
        public ?int $sizeBytes = null,
        public ?string $serverVersion = null,
        public ?int $rootUid = null,
        public ?int $rootGid = null,
        /** URL sources: what answered ("PostgreSQL 16.4"). */
        public ?string $sourceVersion = null,
        public ?string $error = null,
    ) {}
}
