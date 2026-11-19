<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\BackupsSummaryState;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * The Backups page's headline: one state and the counts behind it.
 */
final class BackupsSummaryData extends Data
{
    /**
     * @param  list<BackupAttentionData>  $attention
     */
    public function __construct(
        public BackupsSummaryState $state,
        public int $total,
        public int $ok,
        /** Running and queued. */
        public int $running,
        public int $queued,
        public int $waiting,
        public int $failed,
        public int $overdue,
        public array $attention,
        public ?CarbonInterface $lastSucceededAt,
        public ?CarbonInterface $nextRunAt,
    ) {}
}
