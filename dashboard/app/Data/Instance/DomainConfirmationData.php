<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/** The platform domain the owner continued with during onboarding. */
final class DomainConfirmationData extends Data
{
    public function __construct(
        public string $domain,
        /** Whether its DNS check passed then; null: confirmed before the check existed. */
        public ?bool $verified,
        public string $at,
    ) {}
}
