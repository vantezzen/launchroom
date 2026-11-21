<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

/**
 * A known build problem the server recognised in the build output
 * (`code`, e.g. `pnpm-outdated-lockfile`), with its message.
 */
final class ApiFailureHintData extends Data
{
    public function __construct(
        public string $code,
        public string $message,
    ) {}
}
