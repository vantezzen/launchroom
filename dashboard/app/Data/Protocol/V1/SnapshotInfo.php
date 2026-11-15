<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * One snapshot in BackupSnapshotsResult (protocol SnapshotInfo).
 */
final class SnapshotInfo extends Data
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        #[Regex(Grammar::SNAPSHOT_ID)]
        public string $id,
        public string $time,
        #[Max(32)]
        public array $tags,
        #[Min(0)]
        public int|Optional $sizeBytes = new Optional,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'tags.*' => ['string', 'regex:'.Grammar::BACKUP_TAG],
        ];
    }
}
