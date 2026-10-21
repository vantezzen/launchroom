<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/**
 * A GitHub App of one of the owner's teams whose URLs point at the dashboard
 * (they don't follow a switch by themselves).
 */
final class DashboardGitHubAppData extends Data
{
    public function __construct(
        public string $name,
        public string $team,
        /** The app's page on GitHub ("App settings" for its owners). */
        public string $appUrl,
        public bool $hasWebhook,
    ) {}
}
