<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Data\Settings\NetworkMemberData;
use App\Enums\ServerStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Server;
use App\Models\User;
use App\Queries\AppsDomainSetup;
use App\Queries\IngressSettingsView;
use App\Queries\ServerMesh;
use App\Services\Mesh\MeshPolicy;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Network (instance owners; docs/08):
 * the private network (docs/05 §2) and public traffic & certificates
 * (docs/05 §4–5) on one page. The actions stay on their existing routes
 * (servers/mesh/*, servers/ingress/*).
 */
final class NetworkSettingsController extends Controller
{
    public function edit(#[CurrentUser] User $user, ServerMesh $mesh, IngressSettingsView $ingress, AppsDomainSetup $appsDomain): Response
    {
        Gate::authorize('manageInstance');

        $controlPlaneIp = $mesh->controlPlaneIp();
        $servers = TeamSettingsController::currentTeam($user)->servers()
            ->where('status', '!=', ServerStatus::Revoked)
            ->orderByDesc('is_control_plane')
            ->orderBy('name')
            ->get();

        return Inertia::render('settings/network', [
            'mesh' => $mesh->overview($user),
            // Who is on the private network and how each reaches the dashboard's server.
            'members' => $servers->map(fn (Server $server): NetworkMemberData => new NetworkMemberData(
                $server->public_id,
                $server->name,
                $server->is_control_plane,
                $server->status === ServerStatus::Online,
                $mesh->summary($server, $controlPlaneIp),
            ))->values()->all(),
            // For "bring your own Tailscale": the ACL the built-in mesh uses.
            'recommendedPolicy' => (string) json_encode(MeshPolicy::document('autogroup:admin'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'tags' => MeshPolicy::allTags(),
            'ingress' => $ingress->get(),
            'appsDomain' => $appsDomain->get(),
        ]);
    }
}
