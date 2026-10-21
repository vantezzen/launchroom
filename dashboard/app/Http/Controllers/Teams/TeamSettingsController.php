<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\UpdateConsoleRecording;
use App\Data\Teams\ConsoleRecordingData;
use App\Data\Teams\InvitationData;
use App\Data\Teams\MemberData;
use App\Data\Teams\TeamData;
use App\Enums\Permission;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Members and invitations of the current team.
 */
final class TeamSettingsController extends Controller
{
    public function edit(#[CurrentUser] User $user): Response
    {
        $team = $user->currentTeam()->firstOrFail();
        Gate::authorize('view', $team);

        $role = $user->roleIn($team);
        $canManage = Gate::allows('manageMembers', $team);

        $members = $team->memberships()->with('user')->orderBy('id')->get()
            ->map(fn (Membership $membership): MemberData => MemberData::fromMembership(
                $membership,
                $membership->user_id === $user->id,
                Gate::allows('update', $membership),
                Gate::allows('delete', $membership),
                Gate::allows('grant', $membership) ? array_values(array_filter(
                    Permission::grantable(),
                    static fn (Permission $permission): bool => $user->hasTeamPermission($team, $permission),
                )) : [],
            ))->all();

        $invitations = $canManage
            ? $team->invitations()->with('inviter')->whereNull('accepted_at')->latest()->get()
                ->map(fn (TeamInvitation $invitation): InvitationData => InvitationData::fromInvitation($invitation))->all()
            : [];

        return Inertia::render('settings/team', [
            'team' => TeamData::fromTeam($team, $role),
            'members' => $members,
            'invitations' => $invitations,
            'canManageMembers' => $canManage,
            // Opt-in console transcripts (docs/03 §11.5); owners switch it.
            'consoleRecording' => [
                'enabled' => $team->settings->recordConsoleSessions,
                'canChange' => Gate::allows('update', $team),
            ],
            'assignableRoles' => array_values(array_filter(
                TeamRole::cases(),
                fn (TeamRole $candidate): bool => $role?->canGrant($candidate) ?? false,
            )),
        ]);
    }

    /**
     * Opt-in console transcripts for the team (docs/03 §11.5).
     */
    public function recording(Request $request, ConsoleRecordingData $data, #[CurrentUser] User $user, UpdateConsoleRecording $update): RedirectResponse
    {
        $team = self::currentTeam($user);
        Gate::authorize('update', $team);

        $update->handle($team, $data->enabled, $this->auditActor($request, $user));

        return back();
    }

    /**
     * The team the settings page manages.
     */
    public static function currentTeam(User $user): Team
    {
        return $user->currentTeam()->firstOrFail();
    }
}
