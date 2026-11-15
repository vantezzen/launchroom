<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\RestoreMode;
use App\Support\Protocol\Grammar;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Restore a snapshot, or (`pointInTime`) a Postgres database to a point in
 * time: `at` (ISO 8601) or, without it, the newest point. Replacing the
 * original asks for its name (`confirmation`).
 */
final class RestoreSnapshotData extends Data
{
    public function __construct(
        public RestoreMode $mode,
        public ?string $snapshot = null,
        #[Max(63)]
        public ?string $confirmation = null,
        public bool $pointInTime = false,
        public ?string $at = null,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'snapshot' => ['exclude_if:pointInTime,true', 'required', 'string', 'regex:'.Grammar::SNAPSHOT_ID],
            'at' => ['nullable', 'date'],
        ];
    }

    public function point(): ?CarbonImmutable
    {
        return $this->at === null ? null : CarbonImmutable::parse($this->at)->utc();
    }
}
