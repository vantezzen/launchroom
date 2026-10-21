<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * An earlier dashboard origin that still answers: browsers are redirected,
 * launchroomd keeps working there, and until `until` also the API, CLI,
 * GitHub webhooks and the installer.
 */
final class DashboardLegacyOriginData extends Data
{
    public function __construct(
        public string $url,
        public string $host,
        public CarbonInterface $since,
        public CarbonInterface $until,
        public bool $inTransition,
        /** APP_URL: the mesh's coordination server stays here. */
        public bool $isInstallUrl,
    ) {}
}
