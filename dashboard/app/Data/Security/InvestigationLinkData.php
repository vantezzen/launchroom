<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

final class InvestigationLinkData extends Data
{
    public function __construct(
        public string $label,
        public string $href,
        public bool $external = false,
    ) {}
}
