<?php

declare(strict_types=1);

namespace App\Data\Updates;

use Spatie\LaravelData\Data;

/**
 * Result of the last release check: the newest release newer than this
 * dashboard on the configured channel (null = up to date), or an error.
 */
final class ReleaseCheckData extends Data
{
    public function __construct(
        public string $currentVersion,
        public string $channel,
        public ?ReleaseData $available,
        public ?string $checkedAt,
        public ?string $error,
    ) {}
}
