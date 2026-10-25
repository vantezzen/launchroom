<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Data\Backups\RecoveryKitStatusData;
use App\Data\Instance\OnboardingProgressData;
use App\Data\Instance\RealtimeConfigData;
use App\Data\Servers\ControlPlaneLoadData;
use App\Data\Teams\TeamContextData;
use App\Data\Teams\TeamData;
use App\Enums\Permission;
use App\Enums\Security\FindingLevel;
use App\Enums\Security\SecurityFindingStatus;
use App\Models\InstanceSettings;
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
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(
        private readonly OnboardingChecklist $onboarding,
    ) {}

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            // Base URL of the public docs for "Learn more" links (docs/21).
            'docsUrl' => DocsUrl::base(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'teams' => fn (): ?TeamContextData => $this->teamContext($request->user()),
            // The sidebar footer's "launchroom vX.Y.Z" (signed-in users only).
            'version' => fn (): ?string => $request->user() instanceof User ? config()->string('launchroom.version') : null,
            // Persistent "save your recovery kit" warning, instance owners only (docs/14 §4).
            'recoveryKit' => fn (): ?RecoveryKitStatusData => RecoveryKitStatusData::forUser($request->user()),
            // Recovery mode after `install.sh --restore` (docs/14 §5.2): a banner for instance owners.
            'recoveryMode' => fn (): bool => $request->user() instanceof User && $request->user()->is_instance_owner && RecoveryMode::active(),
            // Moving launchroom to a new server (docs/14 §13.4): writes are paused
            // during the hand-over (everyone sees it); after a move instance owners
            // see the checklist banner.
            'instanceMove' => fn (): ?array => $request->user() instanceof User ? $this->instanceMove($request->user()) : null,
            // Reverb client settings at runtime (public values only), signed-in users only.
            'realtime' => fn (): ?RealtimeConfigData => $request->user() instanceof User ? RealtimeConfigData::current() : null,
            // "Get started" in the sidebar until the first-run checklist is done (instance owners).
            'onboardingProgress' => fn (): ?OnboardingProgressData => $this->onboarding->progressFor($request->user()),
            // Quiet dot on Settings in the sidebar when a release is ready (instance owners, cached check only).
            // Calm "heavy load" notice in the sidebar for people who manage the
            // control-plane server (docs/08 §4); one cache read per page.
            'controlPlaneLoad' => fn (): ?ControlPlaneLoadData => resolve(ControlPlaneLoad::class)->forUser($request->user()),
            // The Security entry's badge: open "Action needed" findings of the current team (docs/22 §9.1).
            'securityActionNeeded' => fn (): int => $this->securityActionNeeded($request->user()),
            'updateAvailable' => fn (): bool => $request->user() instanceof User
                && $request->user()->is_instance_owner
                && resolve(ReleaseChecker::class)->cached()->available !== null,
        ];
    }

    private function securityActionNeeded(mixed $user): int
    {
        $teamId = $user instanceof User ? ($user->getAttributes()['current_team_id'] ?? null) : null;

        if (! $user instanceof User || ! is_int($teamId) || ! $user->hasTeamPermission($teamId, Permission::View)) {
            return 0;
        }

        return SecurityFinding::query()
            ->where('team_id', $teamId)
            ->where('status', SecurityFindingStatus::Open->value)
            ->where('level', FindingLevel::ActionNeeded->value)
            ->count();
    }

    /**
     * @return array{frozen: bool, checklist: bool}|null
     */
    private function instanceMove(User $user): ?array
    {
        $settings = InstanceSettings::current();
        $frozen = $settings->frozen_at !== null;
        $checklist = $user->is_instance_owner && $settings->move_checklist !== null;

        return $frozen || $checklist ? ['frozen' => $frozen, 'checklist' => $checklist] : null;
    }

    /**
     * The team switcher and the current team's permissions. UI hints only:
     * every action re-authorizes on the server.
     */
    private function teamContext(mixed $user): ?TeamContextData
    {
        if (! $user instanceof User) {
            return null;
        }

        $teams = $user->teams()->orderBy('name')->get();
        $current = null;
        $all = [];

        foreach ($teams as $team) {
            $data = TeamData::fromTeam($team, $user->roleIn($team));
            $all[] = $data;

            if ($team->id === $user->current_team_id) {
                $current = $data;
            }
        }

        return new TeamContextData($current, $all, $current?->role?->permissions() ?? [], Gate::forUser($user)->allows('create', Team::class));
    }
}
