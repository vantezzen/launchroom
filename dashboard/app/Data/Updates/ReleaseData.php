<?php

declare(strict_types=1);

namespace App\Data\Updates;

use Spatie\LaravelData\Data;

/**
 * A launchroom release from the GitHub Releases API (docs/11 §4). Display
 * data only: the daemon verifies the release bundle's signature itself.
 * `notes` is the release body (markdown), shown as plain text.
 */
final class ReleaseData extends Data
{
    public function __construct(
        public string $version,
        public string $tag,
        public string $name,
        public string $notes,
        public string $url,
        public ?string $publishedAt,
        public bool $prerelease,
        /** LR_DASHBOARD_IMAGE of the release's images.env (digest-pinned) or null. */
        public ?string $dashboardImage,
        /** LR_DAEMON_IMAGE of the release's images.env (digest-pinned) or null. */
        public ?string $daemonImage,
    ) {}
}
