<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Data\Backups\RecoveryKitStatusData;
use App\Data\Inertia\AuthData;
use App\Data\Inertia\InstanceMoveData;
use App\Data\Inertia\SharedPropsData;
use App\Data\Instance\OnboardingProgressData;
use App\Data\Instance\RealtimeConfigData;
use App\Data\Servers\ControlPlaneLoadData;
use App\Data\Servers\FleetStatusData;
use App\Data\Teams\TeamContextData;
use App\Data\Teams\TeamData;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Queries\ControlPlaneLoad;
use App\Queries\OnboardingChecklist;
use App\Queries\ShellSignals;
use App\Services\Instance\RecoveryMode;
use App\Services\Updates\ReleaseChecker;
use App\Support\DocsUrl;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function __construct(
        private readonly OnboardingChecklist $onboarding,
        private readonly ShellSignals $signals,
    ) {}

    /**
     * The keys and value types are {@see SharedPropsData}; closures keep partial reloads from computing them.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $signedIn = $user instanceof User;
        // The user's teams are read once per request: the team switcher and the shell signals share them.
        $loaded = null;
        $teams = static function () use ($user, &$loaded): Collection {
            return $loaded ??= $user instanceof User ? $user->teams()->orderBy('name')->get() : new Collection;
        };
        $currentTeam = static fn (): ?Team => $user instanceof User ? $teams()->firstWhere('id', $user->getAttributes()['current_team_id'] ?? null) : null;

        return [
            ...parent::share($request),
            'name' => config()->string('app.name'),
            'auth' => AuthData::for($user),
            'docsUrl' => DocsUrl::base(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'teams' => fn (): ?TeamContextData => $this->teamContext($user, $teams()),
            'version' => fn (): ?string => $signedIn ? config()->string('launchroom.version') : null,
            'releasesUrl' => fn (): ?string => $signedIn ? $this->releasesUrl() : null,
            'recoveryKit' => fn (): ?RecoveryKitStatusData => RecoveryKitStatusData::forUser($user),
            'recoveryMode' => fn (): bool => $signedIn && $user->is_instance_owner && RecoveryMode::active(),
            'instanceMove' => fn (): ?InstanceMoveData => $signedIn ? InstanceMoveData::for($user) : null,
            'realtime' => fn (): ?RealtimeConfigData => $signedIn ? RealtimeConfigData::current($user) : null,
            'onboardingProgress' => fn (): ?OnboardingProgressData => $this->onboarding->progressFor($user),
            'controlPlaneLoad' => fn (): ?ControlPlaneLoadData => resolve(ControlPlaneLoad::class)->forUser($user),
            'securityActionNeeded' => fn (): int => $this->signals->securityActionNeeded($user instanceof User ? $user : null, $currentTeam()),
            'openAlertCount' => fn (): int => $this->signals->openAlerts($user instanceof User ? $user : null, $currentTeam()),
            'fleet' => fn (): ?FleetStatusData => $this->signals->fleet($user instanceof User ? $user : null, $currentTeam()),
            'updateAvailable' => fn (): bool => $signedIn
                && $user->is_instance_owner
                && resolve(ReleaseChecker::class)->cached()->available !== null,
        ];
    }

    /**
     * The team switcher and what the user may do in the current team. UI hints only:
     * every action re-authorizes on the server.
     */
    /**
     * @param  Collection<int, Team>  $teams
     */
    private function teamContext(mixed $user, Collection $teams): ?TeamContextData
    {
        if (! $user instanceof User) {
            return null;
        }

        $current = null;
        $currentTeam = null;
        $all = [];

        foreach ($teams as $team) {
            $data = TeamData::fromTeam($team, $user->roleIn($team));
            $all[] = $data;

            if ($team->id === ($user->getAttributes()['current_team_id'] ?? null)) {
                $current = $data;
                $currentTeam = $team;
            }
        }

        $gate = Gate::forUser($user);

        return new TeamContextData(
            $current,
            $all,
            canCreateTeam: $gate->allows('create', Team::class),
            canSearchLogs: $currentTeam instanceof Team && $gate->allows('searchLogs', $currentTeam),
            canAddServers: $currentTeam instanceof Team && $gate->allows('create', [Server::class, $currentTeam]),
            canInvite: $currentTeam instanceof Team && $gate->allows('create', [TeamInvitation::class, $currentTeam]),
        );
    }

    /**
     * The account menu's "Release notes": the releases of the repository
     * Settings → Updates checks, when that is an owner/name pair.
     */
    private function releasesUrl(): ?string
    {
        $repository = config()->string('launchroom.updates.repository');

        return preg_match('#^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$#D', $repository) === 1
            ? "https://github.com/{$repository}/releases"
            : null;
    }
}
