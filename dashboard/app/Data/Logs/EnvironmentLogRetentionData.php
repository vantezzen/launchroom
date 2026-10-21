<?php

declare(strict_types=1);

namespace App\Data\Logs;

use App\Enums\EnvironmentKind;
use Spatie\LaravelData\Data;

/**
 * Project settings → Logs: one environment's runtime log retention, with
 * what is stored now.
 */
final class EnvironmentLogRetentionData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public EnvironmentKind $kind,
        public bool $enabled,
        public int $days,
        public int $maxMb,
        /** Bytes of runtime lines stored now (uncompressed). */
        public int $storedBytes,
        public ?string $oldestAt,
        public bool $canUpdate,
    ) {}
}
