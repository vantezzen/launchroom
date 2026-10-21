<?php

declare(strict_types=1);

namespace App\Data\Previews;

use App\Enums\PreviewStrategy;
use Spatie\LaravelData\Data;

/**
 * How one database of a preview got its data (docs/06 §5).
 */
final class PreviewDatabaseData extends Data
{
    public function __construct(
        public string $service,
        public PreviewStrategy $strategy,
        /** waiting | restoring | sanitizing | ready | failed (clones); null otherwise. */
        public ?string $state,
        public ?string $message,
        /** The database it was cloned from or is shared with ("staging / postgres"). */
        public ?string $source,
        /** The configured strategy (share/clone) that was replaced by a new empty database (fork previews, docs/09 R20; production toggle off). */
        public ?PreviewStrategy $downgradedFrom = null,
        /** Why: `fork` (fork pull request) or `production` (production data not allowed in previews). */
        public ?string $downgradeReason = null,
    ) {}
}
