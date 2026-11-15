<?php

declare(strict_types=1);

namespace App\Data\Previews;

use App\Enums\PreviewStrategy;
use Spatie\LaravelData\Data;

/**
 * One database of `previews.data.databases` (docs/06 §5): how its data is
 * prepared (a clone restored from a backup, then sanitized) and how far that
 * got, or that it was downgraded to a new empty database.
 */
final class PreviewDatabaseStateData extends Data
{
    /**
     * @param  'waiting'|'restoring'|'sanitizing'|'ready'|'failed'|null  $state  null: not cloned (a downgraded database)
     * @param  'fork'|'production'|null  $downgradeReason
     */
    public function __construct(
        public ?PreviewStrategy $strategy = null,
        public ?string $state = null,
        public ?string $message = null,
        public ?string $restoreRun = null,
        public ?string $source = null,
        public ?string $snapshot = null,
        public ?int $sizeBytes = null,
        public ?string $task = null,
        public ?PreviewStrategy $downgradedFrom = null,
        public ?string $downgradeReason = null,
    ) {}

    /**
     * @param  array<string, mixed>  $changes
     */
    public function changed(array $changes): self
    {
        return self::from([...$this->toArray(), ...$changes]);
    }
}
