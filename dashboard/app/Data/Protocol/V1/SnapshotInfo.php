<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

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
        #[Regex('/^[a-f0-9]{8,64}$/D')]
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
            'tags.*' => ['string', 'regex:/^[a-z][a-z0-9_-]{0,31}:[A-Za-z0-9_.-]{1,128}$/D'],
        ];
    }
}
