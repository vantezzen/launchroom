<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/**
 * One item of the first-run checklist (docs/08 §3.1).
 */
final class OnboardingStepData extends Data
{
    public function __construct(
        /** `server`, `github`, `domain`, `backups` or `project`. */
        public string $id,
        /** `done`, `pending` or `skipped`. */
        public string $status,
        public bool $skippable,
    ) {}
}
