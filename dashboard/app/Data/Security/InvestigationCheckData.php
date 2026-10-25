<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * "What we checked" (docs/22 §7.5): one signal and its window.
 */
final class InvestigationCheckData extends Data
{
    public function __construct(
        public string $label,
        public string $window,
        /** `checked`, `partial` (couldn't check, e.g. a server offline) or `skipped`. */
        public string $state = 'checked',
        public ?string $note = null,
    ) {}
}
