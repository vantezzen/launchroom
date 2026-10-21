<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Step 1 of Settings → Dashboard domain: the domain (grammar checked by
 * StartDashboardDomainChange, docs/05 §4.1).
 */
final class StartDashboardDomainData extends Data
{
    public function __construct(
        #[Max(300)]
        public string $hostname,
    ) {}
}
