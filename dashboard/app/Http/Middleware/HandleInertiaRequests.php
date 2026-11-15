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
use App\Data\Teams\TeamContextData;
use App\Data\Teams\TeamData;
use App\Models\Security\SecurityFinding;
use App\Models\Team;
use App\Models\User;
use App\Queries\ControlPlaneLoad;
use App\Queries\OnboardingChecklist;
use App\Services\Instance\RecoveryMode;
use App\Services\Updates\ReleaseChecker;
use App\Support\DocsUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function __construct(
        private readonly OnboardingChecklist $onboarding,
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

        return [
            ...parent::share($request),
            'name' => config()->string('app.name'),
            'auth' => AuthData::for($user),
            'docsUrl' => DocsUrl::base(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'teams' => fn (): ?TeamContextData => $this->teamContext($user),
            'version' => fn (): ?string => $signedIn ? config()->string('launchroom.version') : null,
            'recoveryKit' => fn (): ?RecoveryKitStatusData => RecoveryKitStatusData::forUser($user),
            'recoveryMode' => fn (): bool => $signedIn && $user->is_instance_owner && RecoveryMode::active(),
            'instanceMove' => fn (): ?InstanceMoveData => $signedIn ? InstanceMoveData::for($user) : null,
            'realtime' => fn (): ?RealtimeConfigData => $signedIn ? RealtimeConfigData::current() : null,
            'onboardingProgress' => fn (): ?OnboardingProgressData => $this->onboarding->progressFor($user),
            'controlPlaneLoad' => fn (): ?ControlPlaneLoadData => resolve(ControlPlaneLoad::class)->forUser($user),
            'securityActionNeeded' => fn (): int => $this->securityActionNeeded($user),
            'updateAvailable' => fn (): bool => $signedIn
                && $user->is_instance_owner
                && resolve(ReleaseChecker::class)->cached()->available !== null,
        ];
    }

    private function securityActionNeeded(mixed $user): int
    {
        $teamId = $user instanceof User ? ($user->getAttributes()['current_team_id'] ?? null) : null;

        if (! $user instanceof User || ! is_int($teamId) || ! Gate::forUser($user)->allows('viewAny', [SecurityFinding::class, $teamId])) {
            return 0;
        }

        return SecurityFinding::query()
            ->where('team_id', $teamId)
            ->actionNeeded()
            ->count();
    }

    /**
     * The team switcher and what the user may do in the current team. UI hints only:
     * every action re-authorizes on the server.
     */
    private function teamContext(mixed $user): ?TeamContextData
    {
        if (! $user instanceof User) {
            return null;
        }

        $teams = $user->teams()->orderBy('name')->get();
        $current = null;
        $currentTeam = null;
        $all = [];

        foreach ($teams as $team) {
            $data = TeamData::fromTeam($team, $user->roleIn($team));
            $all[] = $data;

            if ($team->id === $user->current_team_id) {
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
        );
    }
}
