<?php

declare(strict_types=1);

namespace App\Data\Updates;

use Spatie\LaravelData\Data;

/**
 * A launchroom release from the GitHub Releases API (docs/11 §4). Display
 * data only: the daemon verifies the release bundle's signature itself.
 * `notes` is the release body (markdown), shown as plain text.
 * `cannotInstallReason` is set when the release's images.env could not be
 * read or pins no server image: Settings → Updates then shows that reason
 * and `installerCommand` instead of "Update now".
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
        /** Why the dashboard can't install this release itself (plain words), null when it can. */
        public ?string $cannotInstallReason = null,
        /** The installer command that installs exactly this release by hand. */
        public string $installerCommand = '',
    ) {}
}
