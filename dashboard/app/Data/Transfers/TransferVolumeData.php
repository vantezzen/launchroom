<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use App\Models\Volume;
use Spatie\LaravelData\Data;

final class TransferVolumeData extends Data
{
    public function __construct(
        public string $id,
        public string $mountPath,
    ) {}

    public static function fromVolume(?Volume $volume): ?self
    {
        return $volume instanceof Volume ? new self($volume->public_id, $volume->mount_path) : null;
    }
}
