<?php

declare(strict_types=1);

namespace App\Data\Previews;

use App\Enums\Previews\ForkPolicy;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Project settings → Previews (docs/06 §5).
 */
final class PreviewSettingsInput extends Data
{
    public function __construct(
        public bool $enabled,
        /** Null: staging if it exists, else production. */
        #[Regex('/^env_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public ?string $baseEnvironmentId,
        /** Comma-separated globs of target branches, e.g. "main, release/*"; empty = any. */
        #[Max(255), Regex('/^[A-Za-z0-9._\/*?,\- ]*$/D')]
        public ?string $targetBranches,
        #[Max(50)]
        public ?string $requiredLabel,
        #[Min(1), Max(50)]
        public int $maxConcurrent,
        /** Days without new commits before a preview is removed; 0 = never. */
        #[Min(0), Max(90)]
        public int $expiryDays,
        public ForkPolicy $forkPolicy,
        public bool $protection,
        /** Admins only: allow production databases as clone sources (PII). */
        public bool $allowProductionClone,
    ) {}
}
