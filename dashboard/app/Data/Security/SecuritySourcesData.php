<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * Settings → Security → Vulnerability data (docs/22 §9.8): one switch per
 * public source.
 */
final class SecuritySourcesData extends Data
{
    public function __construct(
        public bool $osv,
        public bool $kev,
        public bool $eol,
    ) {}
}
