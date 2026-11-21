<?php

declare(strict_types=1);

namespace App\Data\AppUpdates;

use App\Enums\AppUpdates\UpdateKind;
use Spatie\LaravelData\Data;

/**
 * A newer release of a running image (docs/04 §14.1), stored in
 * `app_update_checks.candidates` and shown on the service. `digest` is what
 * an update pins; `firstSeenAt` starts the automatic-update settle time.
 */
final class UpdateCandidateData extends Data
{
    public function __construct(
        public UpdateKind $kind,
        /** The tag to run (`v6.3.0`, `6.72.0-alpine`; for a build, the running tag). */
        public string $tag,
        /** For people: `v6.3.0`, or for a moving tag the version it names now, else the tag. */
        public string $version,
        public ?string $digest,
        public ?string $publishedAt,
        public string $firstSeenAt,
        /** The template that pins this version (tested by launchroom). */
        public ?string $curatedBy = null,
    ) {}
}
