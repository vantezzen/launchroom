<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Backups\RecoveryKitStatusData;
use App\Data\Instance\FirstAppData;
use App\Data\Instance\OnboardingData;
use App\Data\Instance\OnboardingProgressData;
use App\Data\Instance\OnboardingStepData;
use App\Data\Servers\ServerData;
use App\Enums\Instance\OnboardingStep;
use App\Enums\Instance\OnboardingStepStatus;
use App\Enums\ServerStatus;
use App\Models\Backups\BackupDestination;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Dns\EdgeAddresses;
use App\Support\GeneratedHostname;
use App\Support\Network\ServerAddress;
use Illuminate\Support\Facades\Gate;
use Laravel\Fortify\Features;

/**
 * The first-run checklist (docs/08 §3.1), for instance owners: this server
 * is connected, GitHub is connected, the platform domain is set, backups are
 * set up (control-plane destination + confirmed recovery kit), and the first
 * app is live, not just created (FirstApp; flows §1 order: server, GitHub,
 * address, first app, backup). Each step is derived from real state; the address step is done
 * once the owner continued after the DNS check (`domainConfirmed`), not when
 * the domain is saved. "Skipped" (domain, GitHub, backups) and "dismissed"
 * live in `instance_settings.onboarding`.
 */
final readonly class OnboardingChecklist
{
    public function __construct(
        private EdgeAddresses $edges,
        private SmallServerHint $smallServer,
        private FirstApp $firstApp,
    ) {}

    public function for(User $user): OnboardingData
    {
        $settings = InstanceSettings::current();
        ['team' => $team, 'server' => $server, 'app' => $app, 'installation' => $installation, 'recoveryKit' => $recoveryKit, 'projectCount' => $projectCount, 'firstApp' => $firstApp, 'steps' => $steps] = $this->state($user, $settings);
        $githubConnected = $installation instanceof GitHubInstallation;
        $ipv4 = ServerAddress::ipv4($server?->public_ipv4);
        $address = $ipv4 ?? ServerAddress::ipv6($server?->public_ipv6);

        return new OnboardingData(
            steps: $steps,
            progress: $this->progress($settings, $steps),
            server: $server instanceof Server ? ServerData::fromServer($server) : null,
            serverAddress: $address,
            githubConnected: $githubConnected,
            githubAppName: $app?->name,
            appsDomain: $settings->apps_domain,
            edgeAddresses: $this->edges->addresses(null),
            freeWildcard: $ipv4 === null ? null : GeneratedHostname::sslipWildcard($ipv4),
            dashboardHost: (string) parse_url(config()->string('app.url'), PHP_URL_HOST),
            teamBackupsConfigured: $team instanceof Team && BackupDestination::query()->where('team_id', $team->id)->exists(),
            recoveryKit: $recoveryKit,
            projectCount: $projectCount,
            canCreateProject: $team instanceof Team && Gate::forUser($user)->allows('create', [Project::class, $team]),
            githubInstallUrl: $app instanceof GitHubApp ? $app->installUrl().'?state=onboarding' : null,
            githubAccount: $installation?->account_login,
            githubRepositories: is_numeric($installation?->getAttribute('repositories_count')) ? (int) $installation->getAttribute('repositories_count') : 0,
            canConnectGitHub: $team instanceof Team && Gate::forUser($user)->allows('create', [GitHubApp::class, $team]),
            recommendTwoFactor: $this->recommendTwoFactor($settings, $user),
            appsDomainVerified: $settings->onboarding?->verified($settings->apps_domain),
            smallServerHint: $team instanceof Team ? $this->smallServer->for($user, $team) : null,
            firstApp: $firstApp,
        );
    }

    private function recommendTwoFactor(InstanceSettings $settings, User $user): bool
    {
        if (! Features::canManageTwoFactorAuthentication()) {
            return false;
        }

        $confirmed = User::query()->whereKey($user->id)->whereNotNull('two_factor_confirmed_at')->exists();

        return ! $confirmed && ! in_array($user->id, $settings->onboarding->twoFactorDismissed ?? [], true);
    }

    /**
     * The sidebar's "Get started" (shared prop): instance owners only.
     */
    public function progressFor(mixed $user): ?OnboardingProgressData
    {
        if (! $user instanceof User || $user->is_instance_owner !== true) {
            return null;
        }

        // Only what the steps need: the rest of the checklist page (edge
        // addresses, the small-server hint, …) would cost queries on every page.
        $settings = InstanceSettings::current();

        return $this->progress($settings, $this->state($user, $settings)['steps']);
    }

    /**
     * What the steps are derived from, shared by the checklist page and the
     * sidebar's progress so both always agree.
     *
     * @return array{team: Team|null, server: Server|null, app: GitHubApp|null, installation: GitHubInstallation|null, recoveryKit: RecoveryKitStatusData, projectCount: int, firstApp: FirstAppData|null, confirmed: bool, steps: list<OnboardingStepData>}
     */
    private function state(User $user, InstanceSettings $settings): array
    {
        // The session's user model may be partial (strict mode); read the team id fresh.
        $teamId = User::query()->whereKey($user->id)->value('current_team_id');
        $team = is_int($teamId) ? Team::query()->whereKey($teamId)->first() : null;
        $server = Server::currentControlPlane();
        $app = $team instanceof Team ? GitHubApp::query()->where('team_id', $team->id)->orderBy('id')->first() : null;
        $installation = $app instanceof GitHubApp ? GitHubInstallation::query()
            ->where('github_app_id', $app->id)
            ->whereNull('removed_at')
            ->whereNull('suspended_at')
            ->withCount('repositories')
            ->orderBy('id')
            ->first() : null;
        $recoveryKit = RecoveryKitStatusData::fromSettings($settings, BackupDestination::forInstance() instanceof BackupDestination);
        $projectCount = $team instanceof Team ? Project::query()->where('team_id', $team->id)->count() : 0;
        $firstApp = $team instanceof Team && $projectCount > 0 ? $this->firstApp->for($team, $settings->onboarding?->firstAppSeenAt !== null) : null;
        $confirmed = $settings->onboarding?->confirmed($settings->apps_domain) === true;

        $steps = $this->steps($settings, [
            OnboardingStep::Server->value => $server instanceof Server && $server->status === ServerStatus::Online,
            OnboardingStep::Github->value => $installation instanceof GitHubInstallation,
            // Done once the owner continued after the DNS check (saving alone isn't).
            OnboardingStep::Domain->value => $confirmed,
            OnboardingStep::Backups->value => ! $recoveryKit->needsAttention,
            // Live, not just created: a running or failed first deploy isn't done.
            OnboardingStep::Project->value => $firstApp?->state === 'live',
        ]);

        return ['team' => $team, 'server' => $server, 'app' => $app, 'installation' => $installation, 'recoveryKit' => $recoveryKit, 'projectCount' => $projectCount, 'firstApp' => $firstApp, 'confirmed' => $confirmed, 'steps' => $steps];
    }

    /**
     * @param  array<string, bool>  $done
     * @return list<OnboardingStepData>
     */
    private function steps(InstanceSettings $settings, array $done): array
    {
        $skipped = $settings->onboarding->skipped ?? [];

        return array_map(static fn (OnboardingStep $step): OnboardingStepData => new OnboardingStepData(
            id: $step,
            status: match (true) {
                $done[$step->value] ?? false => OnboardingStepStatus::Done,
                in_array($step->value, $skipped, true) => OnboardingStepStatus::Skipped,
                default => OnboardingStepStatus::Pending,
            },
            skippable: $step->skippable(),
        ), OnboardingStep::cases());
    }

    /**
     * @param  list<OnboardingStepData>  $steps
     */
    private function progress(InstanceSettings $settings, array $steps): OnboardingProgressData
    {
        $done = count(array_filter($steps, static fn (OnboardingStepData $step): bool => $step->status !== OnboardingStepStatus::Pending));

        return new OnboardingProgressData(
            done: $done,
            total: count($steps),
            complete: $done === count($steps),
            dismissed: $settings->onboarding?->dismissedAt !== null,
        );
    }
}
