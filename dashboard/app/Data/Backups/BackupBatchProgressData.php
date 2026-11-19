<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * The newest "Back up everything now", while it runs and for an hour after.
 */
final class BackupBatchProgressData extends Data
{
    /**
     * @param  list<array{name: string, message: string}>  $refused
     * @param  list<array{itemId: string, name: string, error: string|null}>  $failures
     */
    public function __construct(
        public string $id,
        public CarbonInterface $startedAt,
        public ?CarbonInterface $finishedAt,
        public int $total,
        public int $succeeded,
        public int $failed,
        public int $running,
        public int $queued,
        public array $refused,
        public array $failures,
    ) {}
}
