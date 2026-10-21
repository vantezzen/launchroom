<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\ChangeMemberRole;
use App\Actions\Teams\RemoveMember;
use App\Actions\Teams\UpdateMemberPermissions;
use App\Data\Teams\ChangeMemberRoleData;
use App\Data\Teams\MemberPermissionsData;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class MembershipController extends Controller
{
    public function update(Request $request, Membership $membership, ChangeMemberRoleData $data, #[CurrentUser] User $user, ChangeMemberRole $changeMemberRole): RedirectResponse
    {
        Gate::authorize('update', $membership);

        $changeMemberRole->handle($user, $membership, $data->role, $this->auditActor($request, $user));

        return back();
    }

    /**
     * Console and Data access of a developer (docs/09 R33).
     */
    public function permissions(Request $request, Membership $membership, MemberPermissionsData $data, #[CurrentUser] User $user, UpdateMemberPermissions $update): RedirectResponse
    {
        Gate::authorize('grant', $membership);

        $update->handle($user, $membership, $data->toPermissions(), $this->auditActor($request, $user));

        return back();
    }

    public function destroy(Request $request, Membership $membership, #[CurrentUser] User $user, RemoveMember $removeMember): RedirectResponse
    {
        Gate::authorize('delete', $membership);

        $removeMember->handle($user, $membership, $this->auditActor($request, $user));

        return $membership->user_id === $user->id ? to_route('dashboard') : back();
    }
}
