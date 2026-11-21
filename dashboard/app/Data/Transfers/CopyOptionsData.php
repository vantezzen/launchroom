<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use App\Enums\EnvironmentKind;
use App\Enums\Transfers\DataSubject;
use Spatie\LaravelData\Data;

/**
 * Everything the copy sheet needs to plan a copy into one service
 * (docs/14 §14): the target, the environments it can copy from and what
 * stops while the data is replaced.
 */
final class CopyOptionsData extends Data
{
    /**
     * @param  list<CopySourceData>  $sources
     * @param  list<DependentServiceData>  $dependents
     * @param  list<TransferVolumeData>  $volumes
     * @param  array{inspect: string, copies: string}  $urls
     * @param  array{baseSeconds: int, bytesPerMinute: int}  $stopEstimate  how long the target stops: base + whole minutes per bytesPerMinute of dump
     */
    public function __construct(
        public string $serviceName,
        public DataSubject $subject,
        public ?string $versionLabel,
        public string $environmentName,
        public EnvironmentKind $environmentKind,
        /** Why nothing can be copied into it, or null. */
        public ?string $refusal,
        /** A safety snapshot can be taken (backup storage exists). */
        public bool $hasDestination,
        public array $sources,
        public array $dependents,
        public array $volumes,
        public array $urls,
        public array $stopEstimate,
    ) {}
}
