<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Enums\AppUpdates\UpdateKind;

/**
 * One newer tag worth offering (docs/04 §14.1). `curatedBy` names the
 * template that pins it; `digest` is known for pins (and filled in for the
 * others when the check resolves them).
 */
final readonly class ReleaseCandidate
{
    public function __construct(
        public UpdateKind $kind,
        public string $tag,
        public string $version,
        public ?string $curatedBy = null,
        public ?string $digest = null,
    ) {}
}
