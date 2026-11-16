<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Servers\CancelEnrollment;
use App\Actions\Servers\CreateEnrollment;
use App\Data\Servers\CreateEnrollmentData;
use App\Data\Servers\EnrollmentData;
use App\Enums\ServerRole;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Http\Toast;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\Team;
use App\Models\User;
use App\Queries\ServerMesh;
use App\Queries\ServerRoleHints;
use App\Services\Crypto\Signer;
use App\Support\InstallCommands;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Add server" (docs/08 §3.9): choose roles → one-line install command →
 * live "waiting for server" until the daemon enrolls.
 */
final class ServerEnrollmentController extends Controller
{
    private const string TOKEN_FLASH_KEY = 'launchroom.join_token';

    public function create(ServerRoleHints $roleHints, #[CurrentTeam] Team $team): Response
    {
        Gate::authorize('create', [Server::class, $team]);

        return Inertia::render('servers/create', [
            'roles' => ServerRole::cases(),
            // The first extra server turns on the private network (docs/05 §2).
            'serverCount' => $team->servers()->where('status', '!=', 'revoked')->count(),
            // Contextual notes under "What should it do?", per machine and roles (docs/08 §14).
            'roleHints' => $roleHints->forWizard($team),
        ]);
    }

    public function store(Request $request, CreateEnrollmentData $data, #[CurrentUser] User $user, CreateEnrollment $createEnrollment, #[CurrentTeam] Team $team): RedirectResponse
    {
        Gate::authorize('create', [Server::class, $team]);

        $machine = $data->machine;

        // A Mac never serves traffic (D53); enrollment drops the role anyway, this says so up front.
        if ($machine === 'mac' && in_array(ServerRole::Edge, $data->serverRoles(), true)) {
            throw ValidationException::withMessages(['roles' => __('A Mac can’t serve traffic because Docker runs in a Linux VM there. Use a public server to serve traffic.')]);
        }

        $issued = $createEnrollment->handle($team, $data, $user, $this->auditActor($request, $user));

        // Shown exactly once, on the next page view; only the hash is stored.
        $request->session()->flash(self::TOKEN_FLASH_KEY, $issued->token);

        return to_route('servers.enrollments.show', array_filter([
            'enrollment' => $issued->enrollment,
            'machine' => $machine,
        ]));
    }

    public function show(Request $request, ServerEnrollment $enrollment, Signer $signer, ServerMesh $mesh): Response
    {
        Gate::authorize('view', $enrollment);

        $enrollment->load('server');
        $server = $enrollment->server;
        $token = $request->session()->get(self::TOKEN_FLASH_KEY);

        $machine = $request->query('machine');
        $controlPlaneUrl = rtrim(config()->string('app.url'), '/');

        return Inertia::render('servers/enrollment', [
            'machine' => is_string($machine) && in_array($machine, CreateEnrollmentData::MACHINES, true) ? $machine : null,
            // Approve right in the waiting card (docs/03 §3) when approval is required.
            'canApprove' => $server instanceof Server && Gate::allows('approve', $server),
            // A server that never connected can be removed (its join token is deleted).
            'canCancel' => Gate::allows('delete', $enrollment),
            'enrollment' => EnrollmentData::fromEnrollment($enrollment, $server instanceof Server ? $mesh->summary($server) : null),
            // The connected card draws its link to the dashboard's server once there is one to draw (docs/design/flows.md §10).
            'otherServers' => $server instanceof Server
                ? $enrollment->team->servers()->whereKeyNot($server->id)->notRevoked()->count()
                : 0,
            'command' => is_string($token) ? InstallCommands::server($controlPlaneUrl, $token, $signer->publicKey()) : null,
            'controlPlaneUrl' => $controlPlaneUrl,
        ]);
    }

    public function destroy(Request $request, ServerEnrollment $enrollment, #[CurrentUser] User $user, CancelEnrollment $cancelEnrollment): RedirectResponse
    {
        Gate::authorize('delete', $enrollment);

        $cancelEnrollment->handle($enrollment, $this->auditActor($request, $user));

        Toast::success(__('Removed. Its install command no longer works.'));

        return to_route('servers.index');
    }
}
