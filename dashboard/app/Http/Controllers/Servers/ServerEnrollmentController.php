<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Servers\CancelEnrollment;
use App\Actions\Servers\CreateEnrollment;
use App\Data\Servers\CreateEnrollmentData;
use App\Data\Servers\EnrollmentData;
use App\Enums\ServerRole;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\User;
use App\Queries\ServerRoleHints;
use App\Services\Crypto\Signer;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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

    /** "What kind of machine is it?" tiles of the add-server wizard. */
    private const array MACHINES = ['cloud', 'dedicated', 'home', 'mac', 'board'];

    public function create(#[CurrentUser] User $user, ServerRoleHints $roleHints): Response
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [Server::class, $team]);

        return Inertia::render('servers/create', [
            'roles' => ServerRole::cases(),
            // The first extra server turns on the private network (docs/05 §2).
            'serverCount' => $team->servers()->where('status', '!=', 'revoked')->count(),
            // Contextual notes under "What should it do?", per machine and roles (docs/08 §14).
            'roleHints' => $roleHints->forWizard($team),
        ]);
    }

    public function store(Request $request, CreateEnrollmentData $data, #[CurrentUser] User $user, CreateEnrollment $createEnrollment): RedirectResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [Server::class, $team]);

        // The machine picked in the wizard only shapes copy and the waiting picture.
        $request->validate(['machine' => ['nullable', 'string', Rule::in(self::MACHINES)]]);
        $machine = $request->filled('machine') ? $request->string('machine')->toString() : null;

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

    public function show(Request $request, ServerEnrollment $enrollment, Signer $signer): Response
    {
        Gate::authorize('view', $enrollment);

        $enrollment->load('server');
        $token = $request->session()->get(self::TOKEN_FLASH_KEY);

        $machine = $request->query('machine');

        return Inertia::render('servers/enrollment', [
            'machine' => is_string($machine) && in_array($machine, self::MACHINES, true) ? $machine : null,
            // Approve right in the waiting card (docs/03 §3) when approval is required.
            'canApprove' => $enrollment->server !== null && Gate::allows('approve', $enrollment->server),
            // A server that never connected can be removed (its join token is deleted).
            'canCancel' => Gate::allows('delete', $enrollment),
            'enrollment' => EnrollmentData::fromEnrollment($enrollment),
            'joinToken' => is_string($token) ? $token : null,
            'controlPlaneUrl' => rtrim(config()->string('app.url'), '/'),
            'controlPlaneKey' => $signer->publicKey(),
        ]);
    }

    /**
     * Remove a server that was added but never connected: its join token stops working.
     */
    public function destroy(Request $request, ServerEnrollment $enrollment, #[CurrentUser] User $user, CancelEnrollment $cancelEnrollment): RedirectResponse
    {
        Gate::authorize('delete', $enrollment);

        $cancelEnrollment->handle($enrollment, $this->auditActor($request, $user));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Removed. Its install command no longer works.')]);

        return to_route('servers.index');
    }
}
