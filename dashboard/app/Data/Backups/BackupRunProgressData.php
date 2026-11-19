<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunPhase;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * A backup in flight (queued or running) with what is known about its
 * progress: launchroomd's progress lines (bytes read, restic's own total
 * when it reports one), the last successful size of the same item as the
 * expected size otherwise, and its usual duration.
 */
final class BackupRunProgressData extends Data
{
    public function __construct(
        public string $runId,
        #[LiteralTypeScriptType("'queued' | 'running'")]
        public string $state,
        public BackupRunPhase $phase,
        /** queued/waiting: the server it waits for. */
        public ?string $waitingFor,
        /** waiting: what holds that server's slot ("postgres"). */
        public ?string $waitingBehind,
        public ?int $bytesDone,
        public ?int $bytesExpected,
        /** bytesExpected is the previous size, not a reported total (say "about"). */
        public bool $expectedIsEstimate,
        /** Only when launchroomd reports a real total (0–100). */
        public ?float $percent,
        public ?CarbonInterface $queuedAt,
        public ?CarbonInterface $startedAt,
        public ?int $elapsedMs,
        /** Median duration of the last successful runs of the same item. */
        public ?int $usualDurationMs,
        public BackupReason $reason,
    ) {}
}
