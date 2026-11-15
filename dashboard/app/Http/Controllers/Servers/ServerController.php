<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Servers\ApproveServer;
use App\Actions\Servers\DismissSmallServerHint;
use App\Actions\Servers\RevokeServer;
use App\Actions\Servers\UpdateServerRoles;
use App\Actions\Servers\UpdateServerSettings;
use App\Data\Servers\UpdateServerRolesData;
use App\Data\Servers\UpdateServerSettingsData;
use App\Enums\ServerStatus;
use App\Enums\ServerTab;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Http\Toast;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Queries\ServerPages;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ServerController extends Controller
{
    public function index(#[CurrentUser] User $user, #[CurrentTeam] Team $team, ServerPages $pages): Response
    {
        Gate::authorize('viewAny', [Server::class, $team]);

        return Inertia::render('servers/index', $pages->index($team, $user));
    }

    /**
     * `/servers/{server}/metrics`, the tab's former name (D75): alerts and
     * bookmarks still link there, so it redirects with the query.
     */
    public function movedMetrics(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('view', $server);
        $url = route('servers.tab', [$server, ServerTab::Resources->value], false);
        $query = $request->query();

        return redirect()->to($query === [] ? $url : $url.'?'.http_build_query($query), 301);
    }

    public function show(Server $server, #[CurrentUser] User $user, ServerPages $pages, ?ServerTab $tab = null): Response
    {
        Gate::authorize('view', $server);

        return Inertia::render('servers/show', $pages->show($server, $user, $tab ?? ServerTab::Overview));
    }

    /**
     * Dismisses "This server is small" for the current user (docs/08 §14).
     */
    public function dismissSmallServerHint(#[CurrentUser] User $user, DismissSmallServerHint $dismiss): RedirectResponse
    {
        Gate::authorize('create', [Server::class, CurrentTeam::of($user)]);

        $dismiss->handle($user);

        return back();
    }

    public function updateSettings(Request $request, Server $server, UpdateServerSettingsData $data, #[CurrentUser] User $user, UpdateServerSettings $updateServerSettings): RedirectResponse
    {
        Gate::authorize('update', $server);

        $updateServerSettings->handle($server, $data, $this->auditActor($request, $user));

        return back();
    }

    public function updateRoles(Request $request, Server $server, UpdateServerRolesData $data, #[CurrentUser] User $user, UpdateServerRoles $updateServerRoles): RedirectResponse
    {
        Gate::authorize('updateRoles', $server);

        $updateServerRoles->handle($server, $data, $this->auditActor($request, $user));

        return back();
    }

    public function approve(Request $request, Server $server, #[CurrentUser] User $user, ApproveServer $approveServer): RedirectResponse
    {
        Gate::authorize('approve', $server);

        $approveServer->handle($server, $user, $this->auditActor($request, $user));

        return back();
    }

    public function revoke(Request $request, Server $server, #[CurrentUser] User $user, RevokeServer $revokeServer): RedirectResponse
    {
        Gate::authorize('revoke', $server);
        // A server removed before it was ever approved ran nothing: back to the fleet.
        $neverApproved = $server->status === ServerStatus::PendingApproval;

        $revokeServer->handle($server, $this->auditActor($request, $user));

        if ($neverApproved) {
            Toast::success(__(':name was removed. Its key no longer works.', ['name' => $server->name]));

            return to_route('servers.index');
        }

        return back();
    }
}
