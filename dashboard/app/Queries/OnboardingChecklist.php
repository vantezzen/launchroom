<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Backups\RecoveryKitStatusData;
use App\Data\Instance\OnboardingData;
use App\Data\Instance\OnboardingProgressData;
use App\Data\Instance\OnboardingStepData;
use App\Data\Servers\ServerData;
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
use Illuminate\Support\Facades\Gate;
use Laravel\Fortify\Features;

/**
 * The first-run checklist (docs/08 §3.1), for instance owners: this server
 * is connected, GitHub is connected, the platform domain is set, backups are
 * set up (control-plane destination + confirmed recovery kit), and the first
 * project exists (flows §1 order: server, GitHub, address, first app, backup). Each step is derived from real state; "skipped" (domain,
 * GitHub, backups) and "dismissed" live in `instance_settings.onboarding`.
 */
final readonly class OnboardingChecklist
{
    /** Order of flows §1: the first deploy comes before the optional backup. */
    public const array STEPS = ['server', 'github', 'domain', 'project', 'backups'];

    public const array SKIPPABLE = ['github', 'domain', 'backups'];

    public function __construct(
        private EdgeAddresses $edges,
    ) {}

    public function for(User $user): OnboardingData
    {
        $settings = InstanceSettings::current();
        // The session's user model may be partial (strict mode); read the team id fresh.
        $teamId = User::query()->whereKey($user->id)->value('current_team_id');
        $team = is_int($teamId) ? Team::query()->whereKey($teamId)->first() : null;
        $server = Server::query()->where('is_control_plane', true)->orderBy('id')->first();
        $app = $team instanceof Team ? GitHubApp::query()->where('team_id', $team->id)->orderBy('id')->first() : null;
        $installation = $app instanceof GitHubApp ? GitHubInstallation::query()
            ->where('github_app_id', $app->id)
            ->whereNull('removed_at')
            ->whereNull('suspended_at')
            ->withCount('repositories')
            ->orderBy('id')
            ->first() : null;
        $githubConnected = $installation instanceof GitHubInstallation;
        $recoveryKit = RecoveryKitStatusData::fromSettings($settings, BackupDestination::forInstance() instanceof BackupDestination);
        $projectCount = $team instanceof Team ? Project::query()->where('team_id', $team->id)->count() : 0;
        $address = $server instanceof Server ? ($server->public_ipv4 ?? $server->public_ipv6) : null;

        $steps = $this->steps($settings, [
            'server' => $server instanceof Server && $server->status === ServerStatus::Online,
            'github' => $githubConnected,
            'domain' => is_string($settings->apps_domain) && $settings->apps_domain !== '',
            'backups' => ! $recoveryKit->needsAttention,
            'project' => $projectCount > 0,
        ]);

        return new OnboardingData(
            steps: $steps,
            progress: $this->progress($settings, $steps),
            server: $server instanceof Server ? ServerData::fromServer($server) : null,
            serverAddress: $address,
            githubConnected: $githubConnected,
            githubAppName: $app?->name,
            appsDomain: $settings->apps_domain,
            edgeAddresses: $this->edges->addresses(null),
            sslipExample: $server?->public_ipv4 === null ? null : GeneratedHostname::sslip('web', 'production', 'shop', $server->public_ipv4),
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
        );
    }

    private function recommendTwoFactor(InstanceSettings $settings, User $user): bool
    {
        if (! Features::canManageTwoFactorAuthentication()) {
            return false;
        }

        $confirmed = User::query()->whereKey($user->id)->whereNotNull('two_factor_confirmed_at')->exists();

        return ! $confirmed && ! in_array($user->id, $settings->onboarding['twoFactorDismissed'] ?? [], true);
    }

    /**
     * The sidebar's "Get started" (shared prop): instance owners only.
     */
    public function progressFor(mixed $user): ?OnboardingProgressData
    {
        if (! $user instanceof User || $user->is_instance_owner !== true) {
            return null;
        }

        return $this->for($user)->progress;
    }

    /**
     * @param  array<string, bool>  $done
     * @return list<OnboardingStepData>
     */
    private function steps(InstanceSettings $settings, array $done): array
    {
        $skipped = $settings->onboarding['skipped'] ?? [];

        return array_map(static fn (string $id): OnboardingStepData => new OnboardingStepData(
            id: $id,
            status: ($done[$id] ?? false) ? 'done' : (in_array($id, $skipped, true) ? 'skipped' : 'pending'),
            skippable: in_array($id, self::SKIPPABLE, true),
        ), self::STEPS);
    }

    /**
     * @param  list<OnboardingStepData>  $steps
     */
    private function progress(InstanceSettings $settings, array $steps): OnboardingProgressData
    {
        $done = count(array_filter($steps, static fn (OnboardingStepData $step): bool => $step->status !== 'pending'));

        return new OnboardingProgressData(
            done: $done,
            total: count($steps),
            complete: $done === count($steps),
            dismissed: is_string($settings->onboarding['dismissedAt'] ?? null),
        );
    }
}
