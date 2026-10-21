<?php

declare(strict_types=1);

namespace App\Data\Updates;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Data;

/**
 * Settings → Updates → "How launchroom updates": the release channel and
 * opt-in automatic updates (docs/11 §4).
 */
final class UpdatePreferencesInput extends Data
{
    public function __construct(
        #[In(['stable', 'beta'])]
        public string $channel,
        public bool $autoUpdate,
    ) {}
}
