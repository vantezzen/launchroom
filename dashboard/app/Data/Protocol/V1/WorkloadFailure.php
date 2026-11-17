<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Attributes\UnknownCodeAsNull;
use App\Enums\WorkloadFailureCode;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Report `workloads[].failure` (report.json WorkloadFailure): why the
 * container can't be pulled, created or started right now, typed by
 * launchroomd at the source. An unknown code reads as null.
 */
final class WorkloadFailure extends Data
{
    public function __construct(
        #[UnknownCodeAsNull]
        public ?WorkloadFailureCode $code,
        #[Regex(Report::TIME_PATTERN)]
        public string $at,
        #[Max(1024)]
        public string|Optional $message = new Optional,
    ) {}
}
