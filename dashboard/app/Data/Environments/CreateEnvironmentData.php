<?php

declare(strict_types=1);

namespace App\Data\Environments;

use App\Data\Attributes\PublicIdOf;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
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
        #[PublicIdOf('env')]
        public ?string $baseEnvironmentId = null,
        /** Deploy triggers (docs/06 §2); default: manual only. */
        public ?EnvironmentTriggers $triggers = null,
    ) {}
}
