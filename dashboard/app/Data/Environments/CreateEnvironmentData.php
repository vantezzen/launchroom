<?php

declare(strict_types=1);

namespace App\Data\Environments;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * A new persistent environment, copied from a base environment (default:
 * production). Previews are created by the previews workflow.
 */
final class CreateEnvironmentData extends Data
{
    public function __construct(
        #[Min(1), Max(63)]
        public string $name,
        #[Regex('/^env_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public ?string $baseEnvironmentId = null,
        /** Deploy triggers (docs/06 §2); default: manual only. */
        public ?EnvironmentTriggers $triggers = null,
    ) {}
}
