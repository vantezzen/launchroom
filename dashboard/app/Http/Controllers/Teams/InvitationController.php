<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\InviteMember;
use App\Actions\Teams\RevokeInvitation;
use App\Data\Teams\InviteMemberData;
use App\Data\Teams\InviteMembersData;
use App\Exceptions\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class InvitationController extends Controller
{
    /**
     * Invites one or several people with the same role; each address is its
     * own invitation. Addresses that are already members are skipped and
     * named in the result.
     */
    public function store(Request $request, InviteMembersData $data, #[CurrentUser] User $user, InviteMember $inviteMember): RedirectResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [TeamInvitation::class, $team]);

        $field = $data->emails !== null ? 'emails' : 'email';
        $invited = [];
        $skipped = [];

        foreach ($data->addresses() as $address) {
            try {
                $inviteMember->handle($user, $team, new InviteMemberData($address, $data->role), $this->auditActor($request, $user));
                $invited[] = $address;
            } catch (DomainRuleViolation $e) {
                if ($e->field !== 'email') {
                    throw $e;
                }

                $skipped[$address] = $e->getMessage();
            }
        }

        if ($invited === []) {
            throw ValidationException::withMessages([$field => count($skipped) === 1 ? reset($skipped) : 'Everyone on the list is already a member of the team.']);
        }

        $message = count($invited) === 1 ? "Invitation sent to {$invited[0]}." : 'Invitations sent to '.count($invited).' people.';

        if ($skipped !== []) {
            $message .= ' Already in the team: '.implode(', ', array_keys($skipped)).'.';
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function destroy(Request $request, TeamInvitation $invitation, #[CurrentUser] User $user, RevokeInvitation $revokeInvitation): RedirectResponse
    {
        Gate::authorize('delete', $invitation);

        $revokeInvitation->handle($invitation, $this->auditActor($request, $user));

        return back();
    }
}
