<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use App\Enums\EnvironmentKind;
use App\Enums\Transfers\CopyRunStatus;
use App\Enums\Transfers\CopySourcePoint;
use App\Enums\Transfers\DataSubject;
use Spatie\LaravelData\Data;

/**
 * A copy between environments as the UI sees it (docs/14 §14.2), with its
 * steps derived from its export and import.
 */
final class CopyRunData extends Data
{
    /**
     * @param  list<CopyStepData>  $steps
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $id,
        public CopyRunStatus $status,
        public DataSubject $subject,
        public string $sourceEnvironmentName,
        public EnvironmentKind $sourceEnvironmentKind,
        public string $targetEnvironmentName,
        public ?string $mountPath,
        public CopySourcePoint $point,
        public ?string $snapshotAt,
        public ?int $sizeBytes,
        public array $steps,
        public ?string $error,
        public ?string $actorName,
        public ?string $createdAt,
        public ?string $finishedAt,
        /** Before the import task runs, and the user may. */
        public bool $cancellable,
        /** The target's safety snapshot (to restore after a failed import), or null. */
        public ?string $safetySnapshot,
        /** Import warnings ("The file doesn’t say which tool made it…"). */
        public array $warnings = [],
    ) {}
}
