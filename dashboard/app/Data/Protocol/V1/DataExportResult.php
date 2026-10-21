<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * TaskCompletion.dataExport (protocol/schema/v1/task-completion.json): the
 * file a db_export or volume_export task uploaded (D55, docs/14 §12.2).
 */
final class DataExportResult extends Data
{
    public function __construct(
        #[In(DataImportResult::FORMATS)]
        public string $format,
        #[Min(0)]
        public int $sizeBytes,
        #[Min(0)]
        public int $chunks,
        #[Regex('/^[a-f0-9]{64}$/')]
        public string $sha256,
        #[Min(0)]
        public int $durationMs,
        public bool|Optional $compressed = new Optional,
    ) {}
}
