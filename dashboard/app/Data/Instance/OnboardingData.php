<?php

declare(strict_types=1);

namespace App\Data\Instance;

use App\Data\Backups\RecoveryKitStatusData;
use App\Data\Servers\ServerData;
use Spatie\LaravelData\Data;

/**
 * The first-run checklist page (docs/08 §3.1): each step is derived from real
 * state; skipping and dismissing are stored in `instance_settings.onboarding`.
 */
final class OnboardingData extends Data
{
    /**
     * @param  list<OnboardingStepData>  $steps
     * @param  list<string>  $edgeAddresses  public addresses DNS must point at
     */
    public function __construct(
        public array $steps,
        public OnboardingProgressData $progress,
        /** The control-plane server ("this server"), once its daemon enrolled. */
        public ?ServerData $server,
        public ?string $serverAddress,
        public bool $githubConnected,
        public ?string $githubAppName,
        public ?string $appsDomain,
        public array $edgeAddresses,
        /** What a generated domain looks like without a platform domain. */
        public ?string $sslipExample,
        /** Host of the dashboard (the platform domain should differ, cookie isolation). */
        public string $dashboardHost,
        public bool $teamBackupsConfigured,
        public RecoveryKitStatusData $recoveryKit,
        public int $projectCount,
        public bool $canCreateProject,
        /** Install link of the team's GitHub App, returning to onboarding (`state=onboarding`). */
        public ?string $githubInstallUrl = null,
        /** First connected GitHub account ("acme") and its granted repositories. */
        public ?string $githubAccount = null,
        public int $githubRepositories = 0,
        /** May create the GitHub App inline (team owner/admin). */
        public bool $canConnectGitHub = false,
        /** Two-factor is off and the user hasn't said "Not now" (D33: recommended, never required). */
        public bool $recommendTwoFactor = false,
    ) {}
}
