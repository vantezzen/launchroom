<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use App\Enums\Transfers\DataSubject;
use Spatie\LaravelData\Data;

/**
 * Everything the Backups tab's Move data section shows
 * (resources/js/features/data-transfer). Action URLs are included so the UI
 * needs no route names.
 */
final class ServiceTransfersData extends Data
{
    /**
     * @param  list<TransferVolumeData>  $volumes
     * @param  list<DependentServiceData>  $dependents
     * @param  list<ImportRunData>  $imports
     * @param  list<ExportRunData>  $exports
     * @param  array{self: string, transfers: string, inspect: string, imports: string, exports: string}  $urls
     */
    public function __construct(
        public string $serviceInstanceId,
        public string $serviceName,
        public string $confirmationText,
        public ?DataSubject $subject,
        public ?string $engine,
        public ?string $engineLabel,
        public ?string $engineVersion,
        /** Whether this engine can be imported into at all (not Redis, Valkey, ClickHouse). */
        public bool $importSupported,
        /** Why import or export can't run now (old launchroomd, not running), or null. */
        public ?string $unavailableReason,
        public bool $canImport,
        public bool $canExport,
        /** A team backup destination exists (replacing takes a safety snapshot). */
        public bool $hasDestination,
        public array $volumes,
        public array $dependents,
        public array $imports,
        public array $exports,
        public array $urls,
        public int $chunkBytes,
        public int $maxBytes,
    ) {}
}
